package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/database"
)

func handleDatabaseCreate(w http.ResponseWriter, r *http.Request) {
	var req struct {
		DBName   string `json:"db_name"`
		Username string `json:"username"`
		Password string `json:"password"`
		Engine   string `json:"engine"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.DBName == "" || req.Username == "" || req.Password == "" {
		http.Error(w, "db_name, username, and password required", http.StatusBadRequest)
		return
	}
	if req.Engine == "" {
		req.Engine = "mysql"
	}
	if req.Engine == "postgresql" {
		if err := database.CreatePostgresDatabase(req.DBName, req.Username, req.Password); err != nil {
			http.Error(w, "create postgresql database: "+err.Error(), http.StatusUnprocessableEntity)
			return
		}
		respond(w, http.StatusCreated, map[string]string{
			"status":   "created",
			"db_name":  req.DBName,
			"username": req.Username,
			"engine":   req.Engine,
		})
		return
	}
	if req.Engine != "mysql" {
		http.Error(w, "unsupported database engine", http.StatusBadRequest)
		return
	}
	if err := database.CreateDatabase(req.DBName); err != nil {
		http.Error(w, "create database: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}
	if err := database.CreateUser(req.Username, req.Password); err != nil {
		database.DeleteDatabase(req.DBName) //nolint:errcheck
		http.Error(w, "create user: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}
	if err := database.GrantPrivileges(req.DBName, req.Username); err != nil {
		database.DeleteUser(req.Username)   //nolint:errcheck
		database.DeleteDatabase(req.DBName) //nolint:errcheck
		http.Error(w, "grant privileges: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusCreated, map[string]string{
		"status":   "created",
		"db_name":  req.DBName,
		"username": req.Username,
		"engine":   req.Engine,
	})
}

func handleDatabaseDelete(w http.ResponseWriter, r *http.Request) {
	dbName := chi.URLParam(r, "name")
	username := r.URL.Query().Get("username")
	engine := r.URL.Query().Get("engine")
	if engine == "" {
		engine = "mysql"
	}

	if engine == "postgresql" {
		if err := database.DeletePostgresDatabase(dbName, username); err != nil {
			http.Error(w, err.Error(), http.StatusUnprocessableEntity)
			return
		}
		respond(w, http.StatusOK, map[string]string{"status": "deleted", "db_name": dbName, "engine": engine})
		return
	}
	if engine != "mysql" {
		http.Error(w, "unsupported database engine", http.StatusBadRequest)
		return
	}

	if username != "" {
		database.RevokePrivileges(dbName, username) //nolint:errcheck
		database.DeleteUser(username)               //nolint:errcheck
	}
	if err := database.DeleteDatabase(dbName); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "db_name": dbName})
}

func handleDatabasePasswordChange(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	var req struct {
		Password string `json:"password"`
		Engine   string `json:"engine"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.Engine == "" {
		req.Engine = "mysql"
	}
	if req.Engine == "postgresql" {
		if err := database.ChangePostgresUserPassword(username, req.Password); err != nil {
			http.Error(w, err.Error(), http.StatusUnprocessableEntity)
			return
		}
		respond(w, http.StatusOK, map[string]string{"status": "updated", "username": username, "engine": req.Engine})
		return
	}
	if req.Engine != "mysql" {
		http.Error(w, "unsupported database engine", http.StatusBadRequest)
		return
	}
	if err := database.ChangeUserPassword(username, req.Password); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "updated", "username": username})
}

func handleDatabaseStats(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Databases []struct {
			DBName string `json:"db_name"`
			Engine string `json:"engine"`
		} `json:"databases"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if len(req.Databases) == 0 {
		respond(w, http.StatusOK, map[string]any{"databases": []map[string]any{}})
		return
	}

	mysqlNames := make([]string, 0)
	postgresNames := make([]string, 0)
	for _, db := range req.Databases {
		engine := db.Engine
		if engine == "" {
			engine = "mysql"
		}

		switch engine {
		case "mysql":
			mysqlNames = append(mysqlNames, db.DBName)
		case "postgresql":
			postgresNames = append(postgresNames, db.DBName)
		default:
			http.Error(w, "unsupported database engine", http.StatusBadRequest)
			return
		}
	}

	mysqlSizes, err := database.DatabaseSizes(mysqlNames)
	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}

	postgresSizes, err := database.PostgresDatabaseSizes(postgresNames)
	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}

	rows := make([]map[string]any, 0, len(req.Databases))
	for _, db := range req.Databases {
		engine := db.Engine
		if engine == "" {
			engine = "mysql"
		}

		sizeBytes := int64(0)
		if engine == "postgresql" {
			sizeBytes = postgresSizes[db.DBName]
		} else {
			sizeBytes = mysqlSizes[db.DBName]
		}

		rows = append(rows, map[string]any{
			"db_name":    db.DBName,
			"engine":     engine,
			"size_bytes": sizeBytes,
		})
	}

	respond(w, http.StatusOK, map[string]any{"databases": rows})
}
