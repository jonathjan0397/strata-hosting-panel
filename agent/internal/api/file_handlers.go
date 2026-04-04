package api

import (
	"fmt"
	"mime"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"strconv"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-panel/agent/internal/files"
)

// ── List directory ─────────────────────────────────────────────────────────────

func handleFileList(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	path := r.URL.Query().Get("path")
	if path == "" {
		path = "/"
	}

	entries, err := files.List(username, path)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	respond(w, http.StatusOK, map[string]any{
		"path":    path,
		"entries": entries,
	})
}

// ── Read file ─────────────────────────────────────────────────────────────────

func handleFileRead(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	path := r.URL.Query().Get("path")

	content, err := files.ReadFile(username, path)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	respond(w, http.StatusOK, map[string]any{
		"path":    path,
		"content": string(content),
	})
}

// ── Write file ────────────────────────────────────────────────────────────────

func handleFileWrite(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req struct {
		Path    string `json:"path"`
		Content string `json:"content"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	if err := files.WriteFile(username, req.Path, []byte(req.Content)); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	files.ChownToUser(username, req.Path)
	respond(w, http.StatusOK, map[string]string{"status": "ok"})
}

// ── Create directory ──────────────────────────────────────────────────────────

func handleFileMkdir(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req struct {
		Path string `json:"path"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	if err := files.MkDir(username, req.Path); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	files.ChownToUser(username, req.Path)
	respond(w, http.StatusOK, map[string]string{"status": "ok"})
}

// ── Rename / move ─────────────────────────────────────────────────────────────

func handleFileRename(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req struct {
		From string `json:"from"`
		To   string `json:"to"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	if err := files.Rename(username, req.From, req.To); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "ok"})
}

// ── Delete ────────────────────────────────────────────────────────────────────

func handleFileDelete(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	path := r.URL.Query().Get("path")

	if err := files.Delete(username, path); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "ok"})
}

// ── Chmod ─────────────────────────────────────────────────────────────────────

func handleFileChmod(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req struct {
		Path string `json:"path"`
		Mode string `json:"mode"` // octal string, e.g. "0755"
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	modeInt, err := strconv.ParseUint(req.Mode, 8, 32)
	if err != nil {
		http.Error(w, fmt.Sprintf("invalid mode %q: %v", req.Mode, err), http.StatusBadRequest)
		return
	}

	if err := files.Chmod(username, req.Path, os.FileMode(modeInt)); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "ok"})
}

// ── Compress ──────────────────────────────────────────────────────────────────

func handleFileCompress(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req struct {
		Paths  []string `json:"paths"`
		Dest   string   `json:"dest"`
		Format string   `json:"format"` // "zip" or "tar.gz"
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.Format == "" {
		req.Format = "zip"
	}

	if err := files.Compress(username, req.Paths, req.Dest, req.Format); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "ok", "dest": req.Dest})
}

// ── Extract ───────────────────────────────────────────────────────────────────

func handleFileExtract(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req struct {
		Path string `json:"path"`
		Dest string `json:"dest"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.Dest == "" {
		req.Dest = filepath.Dir(req.Path)
	}

	if err := files.Extract(username, req.Path, req.Dest); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "ok"})
}

// ── Download ──────────────────────────────────────────────────────────────────

func handleFileDownload(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	path := r.URL.Query().Get("path")

	f, info, err := files.OpenForDownload(username, path)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	defer f.Close()

	ext := filepath.Ext(info.Name())
	ct := mime.TypeByExtension(ext)
	if ct == "" {
		ct = "application/octet-stream"
	}

	w.Header().Set("Content-Type", ct)
	w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename=%q`, info.Name()))
	w.Header().Set("Content-Length", strconv.FormatInt(info.Size(), 10))
	http.ServeContent(w, r, info.Name(), info.ModTime(), f)
}

// ── Upload ────────────────────────────────────────────────────────────────────

func handleFileUpload(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	destDir := r.URL.Query().Get("path")
	if destDir == "" {
		destDir = "/"
	}

	// Limit upload size to 256 MB
	r.Body = http.MaxBytesReader(w, r.Body, 256<<20)

	if err := r.ParseMultipartForm(32 << 20); err != nil {
		http.Error(w, "failed to parse multipart form: "+err.Error(), http.StatusBadRequest)
		return
	}

	var uploaded []string
	for _, headers := range r.MultipartForm.File {
		for _, fh := range headers {
			if err := saveUploadedFile(username, destDir, fh); err != nil {
				http.Error(w, err.Error(), http.StatusBadRequest)
				return
			}
			uploaded = append(uploaded, fh.Filename)
		}
	}

	files.ChownToUser(username, destDir)
	respond(w, http.StatusOK, map[string]any{
		"status":   "ok",
		"uploaded": uploaded,
	})
}

func saveUploadedFile(username, destDir string, fh *multipart.FileHeader) error {
	src, err := fh.Open()
	if err != nil {
		return err
	}
	defer src.Close()
	return files.SaveUpload(username, destDir, fh.Filename, src)
}

