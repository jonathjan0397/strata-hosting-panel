package database

import (
	"bytes"
	"fmt"
	"os"
	"os/exec"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

var (
	dbHost   = envOrDefault("STRATA_DB_HOST", "127.0.0.1")
	dbPort   = envOrDefault("STRATA_DB_PORT", "3306")
	dbRootPw = os.Getenv("STRATA_DB_ROOT_PASSWORD")

	// reName permits database names: lowercase, start with letter, up to 48 chars.
	reName = regexp.MustCompile(`^[a-z][a-z0-9_]{0,47}$`)
	// reUser permits MariaDB usernames up to 16 chars (MariaDB limit).
	reUser = regexp.MustCompile(`^[a-z][a-z0-9_]{0,15}$`)
	// reHost permits common MariaDB host forms without SQL metacharacters.
	reHost = regexp.MustCompile(`^(localhost|%|[A-Za-z0-9][A-Za-z0-9._%-]{0,252})$`)
)

func envOrDefault(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

// mysql runs a SQL statement against MariaDB as root via the CLI.
// The password is passed via MYSQL_PWD env var to avoid process-list exposure.
func mysql(query string) error {
	_, err := mysqlOutput(query)
	return err
}

func mysqlOutput(query string) ([]byte, error) {
	cmd := exec.Command("mysql",
		"-u", "root",
		"-h", dbHost,
		"-P", dbPort,
		"--batch",
		"--silent",
		"-e", query,
	)
	cmd.Env = append(os.Environ(), "MYSQL_PWD="+dbRootPw)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return nil, fmt.Errorf("mysql: %w: %s", err, strings.TrimSpace(string(out)))
	}
	return out, nil
}

// CreateDatabase creates a MariaDB database with utf8mb4 charset.
func CreateDatabase(dbName string) error {
	if !reName.MatchString(dbName) {
		return fmt.Errorf("invalid database name: %s", dbName)
	}
	return mysql(fmt.Sprintf(
		"CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
		dbName,
	))
}

// DeleteDatabase drops a MariaDB database.
func DeleteDatabase(dbName string) error {
	if !reName.MatchString(dbName) {
		return fmt.Errorf("invalid database name: %s", dbName)
	}
	return mysql(fmt.Sprintf("DROP DATABASE IF EXISTS `%s`;", dbName))
}

// CreateUser creates a localhost-restricted MariaDB user.
func CreateUser(username, password string) error {
	return CreateUserAtHost(username, password, "localhost")
}

// CreateUserAtHost creates a MariaDB user for a specific allowed host.
func CreateUserAtHost(username, password, host string) error {
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}
	if !reHost.MatchString(host) {
		return fmt.Errorf("invalid host: %s", host)
	}
	// Use parameterised-style escaping: password goes in via single-quoted escaped literal.
	escaped := strings.ReplaceAll(password, "'", "\\'")
	if err := mysql(fmt.Sprintf(
		"CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s';",
		username, host, escaped,
	)); err != nil {
		return err
	}
	return mysql(fmt.Sprintf(
		"ALTER USER '%s'@'%s' IDENTIFIED BY '%s';",
		username, host, escaped,
	))
}

// DeleteUser drops a MariaDB user.
func DeleteUser(username string) error {
	return DeleteUserAtHost(username, "localhost")
}

// DeleteUserAtHost drops a MariaDB user for a specific host.
func DeleteUserAtHost(username, host string) error {
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}
	if !reHost.MatchString(host) {
		return fmt.Errorf("invalid host: %s", host)
	}
	return mysql(fmt.Sprintf("DROP USER IF EXISTS '%s'@'%s';", username, host))
}

// GrantPrivileges grants a user full privileges on a database.
func GrantPrivileges(dbName, username string) error {
	return GrantPrivilegesAtHost(dbName, username, "localhost")
}

// GrantPrivilegesAtHost grants a user full privileges on a database from a host.
func GrantPrivilegesAtHost(dbName, username, host string) error {
	if !reName.MatchString(dbName) || !reUser.MatchString(username) {
		return fmt.Errorf("invalid db name or username")
	}
	if !reHost.MatchString(host) {
		return fmt.Errorf("invalid host: %s", host)
	}
	if err := mysql(fmt.Sprintf(
		"GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%s';",
		dbName, username, host,
	)); err != nil {
		return err
	}
	return mysql("FLUSH PRIVILEGES;")
}

// RevokePrivileges revokes all privileges of a user on a database.
func RevokePrivileges(dbName, username string) error {
	return RevokePrivilegesAtHost(dbName, username, "localhost")
}

// RevokePrivilegesAtHost revokes all privileges of a user on a database from a host.
func RevokePrivilegesAtHost(dbName, username, host string) error {
	if !reName.MatchString(dbName) || !reUser.MatchString(username) {
		return fmt.Errorf("invalid db name or username")
	}
	if !reHost.MatchString(host) {
		return fmt.Errorf("invalid host: %s", host)
	}
	if err := mysql(fmt.Sprintf(
		"REVOKE ALL PRIVILEGES ON `%s`.* FROM '%s'@'%s';",
		dbName, username, host,
	)); err != nil {
		// Ignore if user/db doesn't exist.
		if strings.Contains(err.Error(), "1141") || strings.Contains(err.Error(), "1410") {
			return nil
		}
		return err
	}
	return mysql("FLUSH PRIVILEGES;")
}

// ChangeUserPassword updates a user's password.
func ChangeUserPassword(username, password string) error {
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}
	escaped := strings.ReplaceAll(password, "'", "\\'")
	if err := mysql(fmt.Sprintf(
		"ALTER USER '%s'@'localhost' IDENTIFIED BY '%s';",
		username, escaped,
	)); err != nil {
		return err
	}
	return mysql(fmt.Sprintf(
		"ALTER USER '%s'@'127.0.0.1' IDENTIFIED BY '%s';",
		username, escaped,
	))
}

func DatabaseSizes(dbNames []string) (map[string]int64, error) {
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

	quoted := make([]string, 0, len(validNames))
	for _, name := range validNames {
		quoted = append(quoted, "'"+name+"'")
	}

	query := fmt.Sprintf(`
SELECT table_schema, COALESCE(SUM(data_length + index_length), 0)
FROM information_schema.tables
WHERE table_schema IN (%s)
GROUP BY table_schema;
`, strings.Join(quoted, ","))

	out, err := mysqlOutput(query)
	if err != nil {
		return nil, err
	}

	sizes := make(map[string]int64, len(validNames))
	for _, name := range validNames {
		sizes[name] = 0
	}

	for _, line := range bytes.Split(bytes.TrimSpace(out), []byte("\n")) {
		if len(line) == 0 {
			continue
		}
		parts := bytes.SplitN(line, []byte("\t"), 2)
		if len(parts) != 2 {
			continue
		}

		size, err := strconv.ParseInt(string(parts[1]), 10, 64)
		if err != nil {
			return nil, fmt.Errorf("parse database size for %s: %w", string(parts[0]), err)
		}

		sizes[string(parts[0])] = size
	}

	return sizes, nil
}
