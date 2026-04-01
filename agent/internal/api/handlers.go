package api

import (
	"encoding/json"
	"net/http"
	"os/exec"
	"runtime"
	"strconv"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-panel/agent/internal/system"
)

// Version is injected at build time: -ldflags "-X api.Version=1.0.0"
var Version = "dev"

func respond(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

// ── Health / Version ─────────────────────────────────────────────────────────

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

func handleServiceStart(w http.ResponseWriter, r *http.Request) {
	serviceAction(w, r, "start")
}

func handleServiceStop(w http.ResponseWriter, r *http.Request) {
	serviceAction(w, r, "stop")
}

func handleServiceRestart(w http.ResponseWriter, r *http.Request) {
	serviceAction(w, r, "restart")
}

func handleServiceReload(w http.ResponseWriter, r *http.Request) {
	serviceAction(w, r, "reload")
}

func serviceAction(w http.ResponseWriter, r *http.Request, action string) {
	name := chi.URLParam(r, "name")
	if err := system.ServiceAction(name, action); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": action + "ed", "service": name})
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

// ── Nginx ─────────────────────────────────────────────────────────────────────

func handleNginxVhostCreate(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handleNginxVhostDelete(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handleNginxReload(w http.ResponseWriter, r *http.Request) {
	if err := system.ServiceAction("nginx", "reload"); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "reloaded"})
}

// ── PHP ───────────────────────────────────────────────────────────────────────

func handlePHPPoolCreate(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handlePHPPoolDelete(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handlePHPPoolVersionSet(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

// ── SSL ───────────────────────────────────────────────────────────────────────

func handleSSLIssue(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

func handleSSLDelete(w http.ResponseWriter, r *http.Request) {
	respond(w, http.StatusNotImplemented, map[string]string{"status": "not_implemented"})
}

// ── Agent upgrade ─────────────────────────────────────────────────────────────

func handleAgentUpgrade(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Version     string `json:"version"`
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

	go func() {
		exec.Command("/usr/sbin/strata-agent-upgrade", req.Version, req.DownloadURL).Run()
	}()

	respond(w, http.StatusAccepted, map[string]string{"status": "upgrade_started", "version": req.Version})
}
