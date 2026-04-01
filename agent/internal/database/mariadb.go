package database

import (
	"fmt"
	"os"
	"os/exec"
	"regexp"
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
		return fmt.Errorf("mysql: %w: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
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
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}
	// Use parameterised-style escaping: password goes in via single-quoted escaped literal.
	escaped := strings.ReplaceAll(password, "'", "\\'")
	return mysql(fmt.Sprintf(
		"CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';",
		username, escaped,
	))
}

// DeleteUser drops a MariaDB user.
func DeleteUser(username string) error {
	if !reUser.MatchString(username) {
		return fmt.Errorf("invalid username: %s", username)
	}
	return mysql(fmt.Sprintf("DROP USER IF EXISTS '%s'@'localhost';", username))
}

// GrantPrivileges grants a user full privileges on a database.
func GrantPrivileges(dbName, username string) error {
	if !reName.MatchString(dbName) || !reUser.MatchString(username) {
		return fmt.Errorf("invalid db name or username")
	}
	if err := mysql(fmt.Sprintf(
		"GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'localhost';",
		dbName, username,
	)); err != nil {
		return err
	}
	return mysql("FLUSH PRIVILEGES;")
}

// RevokePrivileges revokes all privileges of a user on a database.
func RevokePrivileges(dbName, username string) error {
	if !reName.MatchString(dbName) || !reUser.MatchString(username) {
		return fmt.Errorf("invalid db name or username")
	}
	if err := mysql(fmt.Sprintf(
		"REVOKE ALL PRIVILEGES ON `%s`.* FROM '%s'@'localhost';",
		dbName, username,
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
	return mysql(fmt.Sprintf(
		"ALTER USER '%s'@'localhost' IDENTIFIED BY '%s';",
		username, escaped,
	))
}
