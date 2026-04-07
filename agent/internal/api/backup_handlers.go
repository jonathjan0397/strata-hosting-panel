package api

import (
	"encoding/json"
	"net/http"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/backup"
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

// POST /backups/{username}/upload
func handleBackupUpload(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	r.Body = http.MaxBytesReader(w, r.Body, 20<<30)

	if err := r.ParseMultipartForm(32 << 20); err != nil {
		http.Error(w, "failed to parse multipart form: "+err.Error(), http.StatusBadRequest)
		return
	}

	file, header, err := r.FormFile("file")
	if err != nil {
		http.Error(w, "backup file required", http.StatusBadRequest)
		return
	}
	defer file.Close()

	filename := r.FormValue("filename")
	if filename == "" {
		filename = header.Filename
	}

	entry, err := backup.Store(username, filename, file)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(entry)
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

// POST /backups/{username}/restore/{filename}
func handleBackupRestore(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	filename := chi.URLParam(r, "filename")

	backupPath, err := backup.Path(username, filename)
	if err != nil {
		http.Error(w, err.Error(), http.StatusNotFound)
		return
	}

	backupType := "full"
	if strings.Contains(filename, "_files_") {
		backupType = "files"
	} else if strings.Contains(filename, "_databases_") {
		backupType = "databases"
	}

	if err := backup.Restore(username, backupPath, backupType); err != nil {
		http.Error(w, "restore failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"status": "restored", "type": backupType})
}

// POST /backups/{username}/restore-path/{filename}
func handleBackupRestorePath(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	filename := chi.URLParam(r, "filename")

	var body struct {
		SourcePath string `json:"source_path"`
		TargetPath string `json:"target_path"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "invalid JSON body", http.StatusBadRequest)
		return
	}

	backupPath, err := backup.Path(username, filename)
	if err != nil {
		http.Error(w, err.Error(), http.StatusNotFound)
		return
	}

	backupType := "full"
	if strings.Contains(filename, "_files_") {
		backupType = "files"
	} else if strings.Contains(filename, "_databases_") {
		backupType = "databases"
	}

	if err := backup.RestorePath(username, backupPath, backupType, body.SourcePath, body.TargetPath); err != nil {
		http.Error(w, "path restore failed: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{
		"status":      "restored",
		"type":        backupType,
		"source_path": body.SourcePath,
		"target_path": body.TargetPath,
	})
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
