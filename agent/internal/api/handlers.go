package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os/exec"
	"runtime"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
)

// Version is injected at build time: -ldflags "-X api.Version=1.0.0"
var Version = "dev"

func respond(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

func handleHealth(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusOK, map[string]string{
		"status": "ok",
		"time":   time.Now().UTC().Format(time.RFC3339),
	})
}

func handleVersion(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusOK, map[string]string{
		"version": Version,
		"go":      runtime.Version(),
	})
}

func handleSystemInfo(w http.ResponseWriter, r *http.Request) {
	// TODO: replace with proper /proc parsing
	loadAvg := runCmd("cat", "/proc/loadavg")
	memInfo := runCmd("free", "-m")
	diskInfo := runCmd("df", "-h", "/")

	respond(w, http.StatusOK, map[string]string{
		"load_avg":  loadAvg,
		"mem_info":  memInfo,
		"disk_info": diskInfo,
	})
}

func handleServiceList(w http.ResponseWriter, r *http.Request) {
	services := []string{"nginx", "php8.1-fpm", "php8.2-fpm", "php8.3-fpm",
		"postfix", "dovecot", "rspamd", "pdns", "mariadb", "pure-ftpd"}

	type serviceStatus struct {
		Name   string `json:"name"`
		Active bool   `json:"active"`
	}

	result := make([]serviceStatus, 0, len(services))
	for _, svc := range services {
		out := runCmd("systemctl", "is-active", svc)
		result = append(result, serviceStatus{
			Name:   svc,
			Active: strings.TrimSpace(out) == "active",
		})
	}

	respond(w, http.StatusOK, result)
}

func handleServiceRestart(w http.ResponseWriter, r *http.Request) {
	name := chi.URLParam(r, "name")
	if !isAllowedService(name) {
		http.Error(w, "unknown service", http.StatusBadRequest)
		return
	}
	if err := execCmd("systemctl", "restart", name); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "restarted"})
}

func handleServiceReload(w http.ResponseWriter, r *http.Request) {
	name := chi.URLParam(r, "name")
	if !isAllowedService(name) {
		http.Error(w, "unknown service", http.StatusBadRequest)
		return
	}
	if err := execCmd("systemctl", "reload", name); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "reloaded"})
}

func handleNginxVhostCreate(w http.ResponseWriter, r *http.Request) {
	// TODO: implement nginx vhost provisioning
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handleNginxVhostDelete(w http.ResponseWriter, r *http.Request) {
	// TODO: implement nginx vhost removal
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handleNginxReload(w http.ResponseWriter, r *http.Request) {
	if err := execCmd("systemctl", "reload", "nginx"); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "reloaded"})
}

func handlePHPPoolCreate(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handlePHPPoolDelete(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handlePHPPoolVersionSet(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handleSSLIssue(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handleSSLDelete(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handleAgentUpgrade(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Version    string `json:"version"`
		DownloadURL string `json:"download_url"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid request", http.StatusBadRequest)
		return
	}
	if req.Version == "" || req.DownloadURL == "" {
		http.Error(w, "version and download_url required", http.StatusBadRequest)
		return
	}

	// Run upgrade in background — agent will restart itself via systemd
	go func() {
		exec.Command("/usr/sbin/strata-agent-upgrade", req.Version, req.DownloadURL).Run()
	}()

	respond(w, http.StatusAccepted, map[string]string{"status": "upgrade_started", "version": req.Version})
}

// isAllowedService prevents arbitrary service manipulation
func isAllowedService(name string) bool {
	allowed := map[string]bool{
		"nginx": true, "apache2": true,
		"php8.1-fpm": true, "php8.2-fpm": true, "php8.3-fpm": true,
		"postfix": true, "dovecot": true, "rspamd": true, "opendkim": true,
		"pdns": true, "mariadb": true, "pure-ftpd": true,
		"fail2ban": true, "ufw": true,
	}
	return allowed[name]
}

func runCmd(name string, args ...string) string {
	out, err := exec.Command(name, args...).Output()
	if err != nil {
		return fmt.Sprintf("error: %v", err)
	}
	return strings.TrimSpace(string(out))
}

func execCmd(name string, args ...string) error {
	return exec.Command(name, args...).Run()
}
