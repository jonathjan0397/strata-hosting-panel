package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	stratawebdav "github.com/jonathjan0397/strata-hosting-panel/agent/internal/webdav"
)

func handleWebDAVCreate(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Username string `json:"username"`
		Password string `json:"password"`
		HomeDir  string `json:"home_dir"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.Username == "" || req.Password == "" || req.HomeDir == "" {
		http.Error(w, "username, password, and home_dir required", http.StatusBadRequest)
		return
	}
	if err := stratawebdav.NewStore("").Upsert(req.Username, req.Password, req.HomeDir); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusCreated, map[string]string{
		"status":   "created",
		"username": req.Username,
		"home_dir": req.HomeDir,
	})
}

func handleWebDAVDelete(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	if err := stratawebdav.NewStore("").Delete(username); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "username": username})
}

func handleWebDAVPassword(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	var req struct {
		Password string `json:"password"`
		HomeDir  string `json:"home_dir"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.Password == "" || req.HomeDir == "" {
		http.Error(w, "password and home_dir required", http.StatusBadRequest)
		return
	}
	if err := stratawebdav.NewStore("").Upsert(username, req.Password, req.HomeDir); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "updated", "username": username})
}
