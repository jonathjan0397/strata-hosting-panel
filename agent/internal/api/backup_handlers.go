package api

import (
	"encoding/json"
	"net/http"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-panel/agent/internal/backup"
)

// POST /backups/{username}
// Body: {"type":"files"|"databases"|"full"}
func handleBackupCreate(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var body struct {
		Type string `json:"type"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.Type == "" {
		body.Type = "full"
	}

	entry, err := backup.Create(username, body.Type)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(entry)
}

// GET /backups/{username}
func handleBackupList(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	entries, err := backup.List(username)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(entries)
}

// DELETE /backups/{username}/{filename}
func handleBackupDelete(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	filename := chi.URLParam(r, "filename")

	if err := backup.Delete(username, filename); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	w.WriteHeader(http.StatusNoContent)
}

// GET /backups/{username}/download/{filename}
func handleBackupDownload(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	filename := chi.URLParam(r, "filename")

	path, err := backup.Path(username, filename)
	if err != nil {
		http.Error(w, err.Error(), http.StatusNotFound)
		return
	}

	w.Header().Set("Content-Disposition", "attachment; filename="+filename)
	w.Header().Set("Content-Type", "application/gzip")
	http.ServeFile(w, r, path)
	_ = time.Now() // suppress unused import lint
}
