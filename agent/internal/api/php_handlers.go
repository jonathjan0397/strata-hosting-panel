package api

import (
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"regexp"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/php"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/system"
)

var phpSizeRe = regexp.MustCompile(`^\d+[KMGkmg]?$`)
var phpVersionRe = regexp.MustCompile(`^\d+\.\d+$`)

// PUT /php/pool/{user}/settings
// Updates PHP-FPM pool limits for the given account and reloads php-fpm.
// Respond first so reloading the same php-fpm version cannot sever the
// panel's own FastCGI request on the primary node.
func handlePHPPoolSettings(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "user")

	var req struct {
		PHPVersion     string `json:"php_version"`
		UploadMax      string `json:"upload_max"`
		PostMax        string `json:"post_max"`
		MemoryLimit    string `json:"memory_limit"`
		MaxExecTime    int    `json:"max_exec_time"`
		MaxInputTime   int    `json:"max_input_time"`
		MaxInputVars   int    `json:"max_input_vars"`
		MaxFileUploads int    `json:"max_file_uploads"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.PHPVersion == "" {
		http.Error(w, "php_version is required", http.StatusBadRequest)
		return
	}

	// Validate size strings to prevent config injection.
	for field, val := range map[string]string{
		"upload_max":   req.UploadMax,
		"post_max":     req.PostMax,
		"memory_limit": req.MemoryLimit,
	} {
		if val != "" && !phpSizeRe.MatchString(val) {
			http.Error(w, "invalid "+field+" value", http.StatusBadRequest)
			return
		}
	}

	cfg := php.PoolConfig{
		Username:       username,
		PHPVersion:     req.PHPVersion,
		MaxChildren:    5,
		UploadMax:      phpOrDefault(req.UploadMax, "64M"),
		PostMax:        phpOrDefault(req.PostMax, "64M"),
		MemoryLimit:    phpOrDefault(req.MemoryLimit, "256M"),
		MaxExecTime:    req.MaxExecTime,
		MaxInputTime:   req.MaxInputTime,
		MaxInputVars:   req.MaxInputVars,
		MaxFileUploads: req.MaxFileUploads,
	}
	if cfg.MaxExecTime <= 0 {
		cfg.MaxExecTime = 30
	}
	if cfg.MaxInputTime <= 0 {
		cfg.MaxInputTime = 60
	}
	if cfg.MaxInputVars <= 0 {
		cfg.MaxInputVars = 1000
	}
	if cfg.MaxFileUploads <= 0 {
		cfg.MaxFileUploads = 20
	}

	if err := php.WritePool(cfg); err != nil {
		http.Error(w, "write pool: "+err.Error(), http.StatusInternalServerError)
		return
	}

	fpmService := fmt.Sprintf("php%s-fpm", req.PHPVersion)
	respondAndFlush(w, http.StatusOK, map[string]string{"status": "ok"})

	go func(service string) {
		time.Sleep(350 * time.Millisecond)

		if err := system.ServiceAction(service, "reload"); err != nil {
			log.Printf("php settings: reload %s failed after response: %v", service, err)
		}
	}(fpmService)
}

func phpOrDefault(val, def string) string {
	if val == "" {
		return def
	}
	return val
}

// POST /php/versions/install
// Installs a supported PHP runtime and FPM package set through apt.
func handlePHPVersionInstall(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Version string `json:"version"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	version := strings.TrimSpace(req.Version)
	if !isSupportedPHPVersion(version) {
		http.Error(w, "unsupported php version", http.StatusBadRequest)
		return
	}

	binary := fmt.Sprintf("/usr/bin/php%s", version)
	if st, err := os.Stat(binary); err == nil && !st.IsDir() {
		respond(w, http.StatusOK, map[string]string{
			"status":  "installed",
			"version": version,
			"output":  binary + " already exists",
		})
		return
	}

	if out, err := aptGet("update", "-q"); err != nil {
		respond(w, http.StatusUnprocessableEntity, map[string]string{
			"status": "error",
			"output": "apt-get update failed: " + string(out),
		})
		return
	}

	extensions := []string{"fpm", "cli", "common", "curl", "mbstring", "xml", "zip", "bcmath", "intl", "gd", "mysql", "pgsql", "redis"}
	args := []string{"install", "-y"}
	for _, extension := range extensions {
		args = append(args, fmt.Sprintf("php%s-%s", version, extension))
	}

	out, err := aptGet(args...)
	if err != nil {
		respond(w, http.StatusUnprocessableEntity, map[string]string{
			"status": "error",
			"output": string(out),
		})
		return
	}

	service := fmt.Sprintf("php%s-fpm", version)
	if svcOut, svcErr := exec.Command("systemctl", "enable", "--now", service).CombinedOutput(); svcErr != nil {
		out = append(out, []byte("\n\nsystemctl enable --now failed:\n"+string(svcOut))...)
		respond(w, http.StatusUnprocessableEntity, map[string]string{
			"status": "error",
			"output": string(out),
		})
		return
	}

	respond(w, http.StatusOK, map[string]string{
		"status":  "installed",
		"version": version,
		"output":  string(out),
	})
}

func aptGet(args ...string) ([]byte, error) {
	cmd := exec.Command("apt-get", args...)
	cmd.Env = append(cmd.Environ(), "DEBIAN_FRONTEND=noninteractive")

	type result struct {
		output []byte
		err    error
	}
	ch := make(chan result, 1)
	go func() {
		out, err := cmd.CombinedOutput()
		ch <- result{output: out, err: err}
	}()

	select {
	case res := <-ch:
		return res.output, res.err
	case <-time.After(10 * time.Minute):
		if cmd.Process != nil {
			_ = cmd.Process.Kill()
		}
		return []byte("apt-get timed out"), fmt.Errorf("apt-get timed out")
	}
}

func isSupportedPHPVersion(version string) bool {
	if !phpVersionRe.MatchString(version) {
		return false
	}

	for _, supported := range supportedPHPVersions() {
		if version == supported {
			return true
		}
	}

	return false
}

func supportedPHPVersions() []string {
	versionID := osReleaseValue("VERSION_ID")
	switch strings.Trim(versionID, `"`) {
	case "13":
		return []string{"7.4", "8.0", "8.2", "8.4"}
	case "12":
		return []string{"7.4", "8.0", "8.1", "8.2", "8.3"}
	default:
		return []string{"7.4", "8.0", "8.1", "8.2", "8.3"}
	}
}

func osReleaseValue(key string) string {
	data, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return ""
	}

	prefix := key + "="
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, prefix) {
			return strings.TrimSpace(strings.TrimPrefix(line, prefix))
		}
	}

	return ""
}
