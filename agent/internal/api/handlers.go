package api

import (
	"encoding/json"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"runtime"
	"strconv"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/account"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/apache"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/buildinfo"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/nginx"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/php"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/ssl"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/system"
)

func respond(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

func decodeJSON(w http.ResponseWriter, r *http.Request, v any) bool {
	if err := json.NewDecoder(r.Body).Decode(v); err != nil {
		http.Error(w, "invalid JSON: "+err.Error(), http.StatusBadRequest)
		return false
	}
	return true
}

// ── Health / Version ──────────────────────────────────────────────────────────

func handleHealth(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusOK, map[string]string{
		"status": "ok",
		"time":   time.Now().UTC().Format(time.RFC3339),
	})
}

func handleVersion(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusOK, map[string]string{
		"version": buildinfo.Version,
		"go":      runtime.Version(),
	})
}

// ── System Info ───────────────────────────────────────────────────────────────

func handleSystemInfo(w http.ResponseWriter, r *http.Request) {
	info, err := system.GetInfo()
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, info)
}

// ── Services ──────────────────────────────────────────────────────────────────

func handleServiceList(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusOK, system.GetServiceStatuses())
}

func handleServiceStart(w http.ResponseWriter, r *http.Request)   { serviceAction(w, r, "start") }
func handleServiceStop(w http.ResponseWriter, r *http.Request)    { serviceAction(w, r, "stop") }
func handleServiceRestart(w http.ResponseWriter, r *http.Request) { serviceAction(w, r, "restart") }
func handleServiceReload(w http.ResponseWriter, r *http.Request)  { serviceAction(w, r, "reload") }

func serviceAction(w http.ResponseWriter, r *http.Request, action string) {
	name := chi.URLParam(r, "name")
	if err := system.ServiceAction(name, action); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	status := map[string]string{
		"start":   "started",
		"stop":    "stopped",
		"restart": "restarted",
		"reload":  "reloaded",
	}[action]
	respond(w, http.StatusOK, map[string]string{"status": status, "service": name})
}

// ── Logs ──────────────────────────────────────────────────────────────────────

func handleLogList(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusOK, map[string][]string{"logs": system.ListLogs()})
}

func handleLogRead(w http.ResponseWriter, r *http.Request) {
	service := chi.URLParam(r, "service")
	lines := 100
	if q := r.URL.Query().Get("lines"); q != "" {
		if n, err := strconv.Atoi(q); err == nil {
			lines = n
		}
	}
	entries, err := system.ReadLog(service, lines)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	respond(w, http.StatusOK, map[string]any{
		"service": service,
		"lines":   len(entries),
		"entries": entries,
	})
}

// ── Accounts ──────────────────────────────────────────────────────────────────

func handleAccountProvision(w http.ResponseWriter, r *http.Request) {
	var req account.ProvisionRequest
	if !decodeJSON(w, r, &req) {
		return
	}
	result, err := account.Provision(req)
	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusCreated, result)
}

func handleAccountDeprovision(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	if err := account.Deprovision(username); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deprovisioned", "username": username})
}

// ── Nginx ─────────────────────────────────────────────────────────────────────

func handleNginxVhostCreate(w http.ResponseWriter, r *http.Request) {
	var cfg nginx.VhostConfig
	if !decodeJSON(w, r, &cfg) {
		return
	}

	var err error
	if cfg.WebServer == "apache" {
		err = apache.WriteVhost(apache.VhostConfig{
			Domain: cfg.Domain, Username: cfg.Username, DocumentRoot: cfg.DocumentRoot,
			PHPVersion: cfg.PHPVersion, PHPSocket: cfg.PHPSocket,
			SSLEnabled: cfg.SSLEnabled, SSLCert: cfg.SSLCert, SSLKey: cfg.SSLKey,
			CustomDirectives: cfg.CustomDirectives,
		})
	} else {
		err = nginx.WriteVhost(cfg)
	}

	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusCreated, map[string]string{"status": "created", "domain": cfg.Domain})
}

func handleNginxVhostDelete(w http.ResponseWriter, r *http.Request) {
	domain := chi.URLParam(r, "domain")
	webServer := r.URL.Query().Get("web_server")

	var err error
	if webServer == "apache" {
		err = apache.RemoveVhost(domain)
	} else {
		err = nginx.RemoveVhost(domain)
	}

	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "removed", "domain": domain})
}

func handleNginxReload(w http.ResponseWriter, r *http.Request) {
	if err := nginx.TestAndReload(); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "reloaded"})
}

// ── PHP ───────────────────────────────────────────────────────────────────────

func handlePHPPoolCreate(w http.ResponseWriter, r *http.Request) {
	var cfg php.PoolConfig
	if !decodeJSON(w, r, &cfg) {
		return
	}
	if err := php.WritePool(cfg); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	service := "php" + cfg.PHPVersion + "-fpm"
	if err := system.ServiceAction(service, "reload"); err != nil {
		if startErr := system.ServiceAction(service, "start"); startErr != nil {
			http.Error(w, "pool written but fpm reload failed: "+err.Error(), http.StatusInternalServerError)
			return
		}
	}
	if err := waitForPHPSocket(cfg, 20*time.Second); err != nil {
		http.Error(w, "pool written but php socket did not become ready: "+err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusCreated, map[string]string{"status": "created"})
}

func handlePHPPoolDelete(w http.ResponseWriter, r *http.Request) {
	user := chi.URLParam(r, "user")
	version := r.URL.Query().Get("version")
	if version == "" {
		version = "8.3"
	}
	if err := php.RemovePool(user, version); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	system.ServiceAction("php"+version+"-fpm", "reload") //nolint:errcheck
	respond(w, http.StatusOK, map[string]string{"status": "removed"})
}

func handlePHPPoolVersionSet(w http.ResponseWriter, r *http.Request) {
	user := chi.URLParam(r, "user")
	var req struct {
		OldVersion string `json:"old_version"`
		NewVersion string `json:"new_version"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	// Read old pool config and rewrite for new version
	oldCfg := php.DefaultPool(user, req.OldVersion)
	newCfg := oldCfg
	newCfg.PHPVersion = req.NewVersion

	if err := php.RemovePool(user, req.OldVersion); err != nil {
		http.Error(w, "remove old pool: "+err.Error(), http.StatusInternalServerError)
		return
	}
	if err := php.WritePool(newCfg); err != nil {
		http.Error(w, "write new pool: "+err.Error(), http.StatusInternalServerError)
		return
	}
	system.ServiceAction("php"+req.OldVersion+"-fpm", "reload") //nolint:errcheck
	system.ServiceAction("php"+req.NewVersion+"-fpm", "reload") //nolint:errcheck

	respond(w, http.StatusOK, map[string]string{"status": "updated", "version": req.NewVersion})
}

func waitForPHPSocket(cfg php.PoolConfig, timeout time.Duration) error {
	socketPath := "/run/php/php" + cfg.PHPVersion + "-fpm-" + cfg.Username + ".sock"
	deadline := time.Now().Add(timeout)

	for time.Now().Before(deadline) {
		if info, err := os.Stat(socketPath); err == nil && info.Mode()&os.ModeSocket != 0 {
			conn, dialErr := net.DialTimeout("unix", socketPath, 250*time.Millisecond)
			if dialErr == nil {
				conn.Close()
				return nil
			}
		}

		time.Sleep(250 * time.Millisecond)
	}

	return os.ErrNotExist
}

// ── SSL ───────────────────────────────────────────────────────────────────────

func handleSSLIssue(w http.ResponseWriter, r *http.Request) {
	var req ssl.IssueRequest
	if !decodeJSON(w, r, &req) {
		return
	}
	paths, err := ssl.Issue(req)
	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, paths)
}

func handleSSLDelete(w http.ResponseWriter, r *http.Request) {
	domain := chi.URLParam(r, "domain")
	if err := ssl.Remove(domain); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "removed", "domain": domain})
}

// ── Agent upgrade ─────────────────────────────────────────────────────────────

func handleAgentUpgrade(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Version     string `json:"version"`
		DownloadURL string `json:"download_url"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.Version == "" || req.DownloadURL == "" {
		http.Error(w, "version and download_url required", http.StatusBadRequest)
		return
	}
	downloadURL, err := url.Parse(req.DownloadURL)
	if err != nil || downloadURL.Scheme != "https" {
		http.Error(w, "download_url must be a valid https URL", http.StatusBadRequest)
		return
	}
	allowedPath := strings.HasPrefix(downloadURL.Path, "/jonathjan0397/strata-hosting-panel/releases/download/") ||
		strings.HasPrefix(downloadURL.Path, "/jonathjan0397/strata-hosting-panel/archive/refs/tags/") ||
		strings.HasPrefix(downloadURL.Path, "/jonathjan0397/strata-hosting-panel/archive/refs/heads/")
	if downloadURL.Host != "github.com" || !allowedPath {
		http.Error(w, "download_url host/path not allowed", http.StatusBadRequest)
		return
	}
	go func() {
		exec.Command("/usr/sbin/strata-agent-upgrade", req.Version, req.DownloadURL).Run()
	}()
	respond(w, http.StatusAccepted, map[string]string{"status": "upgrade_started", "version": req.Version})
}
