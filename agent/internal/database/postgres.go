package database

import (
	"bytes"
	"fmt"
	"os"
	"os/exec"
	"sort"
	"strconv"
	"strings"
)

var (
	pgAdminUser = envOrDefault("STRATA_PG_ADMIN_USER", "postgres")
	pgHost      = os.Getenv("STRATA_PG_HOST")
	pgPort      = envOrDefault("STRATA_PG_PORT", "5432")
	pgPassword  = os.Getenv("STRATA_PG_PASSWORD")
)

func postgresArgs(databaseName, query string) []string {
	args := []string{"-v", "ON_ERROR_STOP=1", "-Atqc", query}
	if databaseName != "" {
		args = append([]string{"-d", databaseName}, args...)
	}
	if pgHost != "" || pgPassword != "" {
		args = append([]string{"-U", pgAdminUser, "-p", pgPort}, args...)
		if pgHost != "" {
			args = append([]string{"-h", pgHost}, args...)
		}
	}
	return args
}

func postgres(databaseName, query string) error {
	var cmd *exec.Cmd
	if pgHost == "" && pgPassword == "" {
		args := append([]string{"-u", pgAdminUser, "psql"}, postgresArgs(databaseName, query)...)
		cmd = exec.Command("sudo", args...)
	} else {
		cmd = exec.Command("psql", postgresArgs(databaseName, query)...)
	}

	cmd.Env = os.Environ()
	if pgPassword != "" {
		cmd.Env = append(cmd.Env, "PGPASSWORD="+pgPassword)
	}

	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("postgres: %w: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}

func pgIdent(name string) string {
	return `"` + strings.ReplaceAll(name, `"`, `""`) + `"`
}

func pgLiteral(value string) string {
	return "'" + strings.ReplaceAll(value, "'", "''") + "'"
}

// CreatePostgresDatabase creates a PostgreSQL database owned by a matching role.
func CreatePostgresDatabase(dbName, username, password string) error {
	if !reName.MatchString(dbName) {
		return fmt.Errorf("invalid database name: %s", dbName)
	}
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}

	if err := EnsurePostgresUser(username, password); err != nil {
		return err
	}

	if err := postgres("", fmt.Sprintf("CREATE DATABASE %s OWNER %s ENCODING 'UTF8';", pgIdent(dbName), pgIdent(username))); err != nil {
		DeletePostgresUser(username) //nolint:errcheck
		return err
	}

	return GrantPostgresPrivileges(dbName, username)
}

// DeletePostgresDatabase drops a PostgreSQL database and role.
func DeletePostgresDatabase(dbName, username string) error {
	if !reName.MatchString(dbName) {
		return fmt.Errorf("invalid database name: %s", dbName)
	}
	if username != "" && !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}

	if err := postgres("", fmt.Sprintf("DROP DATABASE IF EXISTS %s;", pgIdent(dbName))); err != nil {
		return err
	}
	if username != "" {
		return DeletePostgresUser(username)
	}
	return nil
}

// DeletePostgresUser drops a PostgreSQL role.
func DeletePostgresUser(username string) error {
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}
	return postgres("", fmt.Sprintf("DROP ROLE IF EXISTS %s;", pgIdent(username)))
}

// EnsurePostgresUser creates a PostgreSQL role or rotates its password if it already exists.
func EnsurePostgresUser(username, password string) error {
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}
	if err := postgres("", fmt.Sprintf("CREATE ROLE %s LOGIN PASSWORD %s;", pgIdent(username), pgLiteral(password))); err != nil {
		if !strings.Contains(err.Error(), "already exists") {
			return err
		}
		return ChangePostgresUserPassword(username, password)
	}
	return nil
}

// GrantPostgresPrivileges grants a role access to a PostgreSQL database and public schema.
func GrantPostgresPrivileges(dbName, username string) error {
	if !reName.MatchString(dbName) || !reUser.MatchString(username) {
		return fmt.Errorf("invalid db name or username")
	}
	if err := postgres("", fmt.Sprintf("GRANT ALL PRIVILEGES ON DATABASE %s TO %s;", pgIdent(dbName), pgIdent(username))); err != nil {
		return err
	}
	if err := postgres(dbName, fmt.Sprintf("GRANT ALL ON SCHEMA public TO %s;", pgIdent(username))); err != nil {
		return err
	}
	return postgres(dbName, fmt.Sprintf("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO %s;", pgIdent(username)))
}

// RevokePostgresPrivileges revokes role access from a PostgreSQL database.
func RevokePostgresPrivileges(dbName, username string) error {
	if !reName.MatchString(dbName) || !reUser.MatchString(username) {
		return fmt.Errorf("invalid db name or username")
	}
	if err := postgres(dbName, fmt.Sprintf("REVOKE ALL ON SCHEMA public FROM %s;", pgIdent(username))); err != nil {
		return err
	}
	return postgres("", fmt.Sprintf("REVOKE ALL PRIVILEGES ON DATABASE %s FROM %s;", pgIdent(dbName), pgIdent(username)))
}

// ChangePostgresUserPassword updates a PostgreSQL role password.
func ChangePostgresUserPassword(username, password string) error {
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}
	return postgres("", fmt.Sprintf("ALTER ROLE %s PASSWORD %s;", pgIdent(username), pgLiteral(password)))
}

func PostgresDatabaseSizes(dbNames []string) (map[string]int64, error) {
	validNames := make([]string, 0, len(dbNames))
	for _, dbName := range dbNames {
		if !reName.MatchString(dbName) {
			return nil, fmt.Errorf("invalid database name: %s", dbName)
		}
		validNames = append(validNames, dbName)
	}

	if len(validNames) == 0 {
		return map[string]int64{}, nil
	}

	sort.Strings(validNames)

	literals := make([]string, 0, len(validNames))
	for _, name := range validNames {
		literals = append(literals, pgLiteral(name))
	}

	query := fmt.Sprintf(`
SELECT datname, pg_database_size(datname)
FROM pg_database
WHERE datname IN (%s);
`, strings.Join(literals, ","))

	var cmd *exec.Cmd
	args := postgresArgs("", query)
	if pgHost == "" && pgPassword == "" {
		cmd = exec.Command("sudo", append([]string{"-u", pgAdminUser, "psql"}, args...)...)
	} else {
		cmd = exec.Command("psql", args...)
	}

	cmd.Env = os.Environ()
	if pgPassword != "" {
		cmd.Env = append(cmd.Env, "PGPASSWORD="+pgPassword)
	}

	out, err := cmd.CombinedOutput()
	if err != nil {
		return nil, fmt.Errorf("postgres: %w: %s", err, strings.TrimSpace(string(out)))
	}

	sizes := make(map[string]int64, len(validNames))
	for _, name := range validNames {
		sizes[name] = 0
	}

	for _, line := range bytes.Split(bytes.TrimSpace(out), []byte("\n")) {
		if len(line) == 0 {
			continue
		}
		parts := bytes.SplitN(line, []byte("|"), 2)
		if len(parts) != 2 {
			continue
		}

		size, err := strconv.ParseInt(strings.TrimSpace(string(parts[1])), 10, 64)
		if err != nil {
			return nil, fmt.Errorf("parse database size for %s: %w", string(parts[0]), err)
		}

		sizes[strings.TrimSpace(string(parts[0]))] = size
	}

	return sizes, nil
}
