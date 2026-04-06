package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/ftp"
)

func handleFTPCreate(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Username string `json:"username"`
		Password string `json:"password"`
		HomeDir  string `json:"home_dir"`
		UID      int    `json:"uid"`
		GID      int    `json:"gid"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.Username == "" || req.Password == "" || req.HomeDir == "" {
		http.Error(w, "username, password, and home_dir required", http.StatusBadRequest)
		return
	}
	if req.UID == 0 {
		req.UID = 33 // www-data
	}
	if req.GID == 0 {
		req.GID = 33
	}
	if err := ftp.CreateAccount(req.Username, req.Password, req.HomeDir, req.UID, req.GID); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusCreated, map[string]string{
		"status":   "created",
		"username": req.Username,
		"home_dir": req.HomeDir,
	})
}

func handleFTPDelete(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	if err := ftp.DeleteAccount(username); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "username": username})
}

func handleFTPPassword(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	var req struct {
		Password string `json:"password"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if err := ftp.ChangePassword(username, req.Password); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "updated", "username": username})
}
