package api

import (
	"net/http"

	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/database"
)

// POST /v1/databases/grant
// Body: {db_name, db_user, password}  — creates user (if new) and grants to db
func handleDatabaseGrant(w http.ResponseWriter, r *http.Request) {
	var req struct {
		DBName   string `json:"db_name"`
		DBUser   string `json:"db_user"`
		Password string `json:"password"`
		Host     string `json:"host"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.DBName == "" || req.DBUser == "" || req.Password == "" {
		http.Error(w, "db_name, db_user, and password required", http.StatusBadRequest)
		return
	}
	if req.Host == "" {
		req.Host = "localhost"
	}
	// CreateUser is idempotent (IF NOT EXISTS)
	if err := database.CreateUserAtHost(req.DBUser, req.Password, req.Host); err != nil {
		http.Error(w, "create user: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}
	if err := database.GrantPrivilegesAtHost(req.DBName, req.DBUser, req.Host); err != nil {
		http.Error(w, "grant: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "granted", "db_name": req.DBName, "db_user": req.DBUser, "host": req.Host})
}

// DELETE /v1/databases/grant
// Body: {db_name, db_user, delete_user}
func handleDatabaseRevoke(w http.ResponseWriter, r *http.Request) {
	var req struct {
		DBName     string `json:"db_name"`
		DBUser     string `json:"db_user"`
		Host       string `json:"host"`
		DeleteUser bool   `json:"delete_user"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.DBName == "" || req.DBUser == "" {
		http.Error(w, "db_name and db_user required", http.StatusBadRequest)
		return
	}
	if req.Host == "" {
		req.Host = "localhost"
	}
	database.RevokePrivilegesAtHost(req.DBName, req.DBUser, req.Host) //nolint:errcheck
	if req.DeleteUser {
		database.DeleteUserAtHost(req.DBUser, req.Host) //nolint:errcheck
	}
	respond(w, http.StatusOK, map[string]string{"status": "revoked", "host": req.Host})
}
