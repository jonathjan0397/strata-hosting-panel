package api

import (
	"net/http"

	"github.com/jonathjan0397/strata-panel/agent/internal/database"
)

// POST /v1/databases/grant
// Body: {db_name, db_user, password}  — creates user (if new) and grants to db
func handleDatabaseGrant(w http.ResponseWriter, r *http.Request) {
	var req struct {
		DBName   string `json:"db_name"`
		DBUser   string `json:"db_user"`
		Password string `json:"password"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.DBName == "" || req.DBUser == "" || req.Password == "" {
		http.Error(w, "db_name, db_user, and password required", http.StatusBadRequest)
		return
	}
	// CreateUser is idempotent (IF NOT EXISTS)
	if err := database.CreateUser(req.DBUser, req.Password); err != nil {
		http.Error(w, "create user: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}
	if err := database.GrantPrivileges(req.DBName, req.DBUser); err != nil {
		http.Error(w, "grant: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "granted", "db_name": req.DBName, "db_user": req.DBUser})
}

// DELETE /v1/databases/grant
// Body: {db_name, db_user, delete_user}
func handleDatabaseRevoke(w http.ResponseWriter, r *http.Request) {
	var req struct {
		DBName     string `json:"db_name"`
		DBUser     string `json:"db_user"`
		DeleteUser bool   `json:"delete_user"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.DBName == "" || req.DBUser == "" {
		http.Error(w, "db_name and db_user required", http.StatusBadRequest)
		return
	}
	database.RevokePrivileges(req.DBName, req.DBUser) //nolint:errcheck
	if req.DeleteUser {
		database.DeleteUser(req.DBUser) //nolint:errcheck
	}
	respond(w, http.StatusOK, map[string]string{"status": "revoked"})
}
