package ftp

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
)

const (
	passwdFile = "/etc/pureftpd/passwd"
	purePwDB   = "/etc/pureftpd/pureftpd.pdb"
)

var reUsername = regexp.MustCompile(`^[a-z][a-z0-9_]{1,31}$`)

// CreateAccount creates a Pure-FTPd virtual account jailed to homeDir.
// uid/gid should match the system account that owns the files (e.g. the
// hosting account's Linux uid/gid).
func CreateAccount(username, password, homeDir string, uid, gid int) error {
	if !reUsername.MatchString(username) {
		return fmt.Errorf("invalid FTP username: %s", username)
	}
	homeDir = filepath.Clean(homeDir)
	if !strings.HasPrefix(homeDir, "/var/www/") || homeDir == "/var/www" {
		return fmt.Errorf("invalid FTP home directory: %s", homeDir)
	}
	if err := os.MkdirAll(homeDir, 0755); err != nil {
		return fmt.Errorf("mkdir %s: %w", homeDir, err)
	}
	cmd := exec.Command("pure-pw", "useradd", username,
		"-u", fmt.Sprintf("%d", uid),
		"-g", fmt.Sprintf("%d", gid),
		"-d", homeDir,
		"-f", passwdFile,
	)
	// pure-pw reads the password twice from stdin.
	cmd.Stdin = strings.NewReader(password + "\n" + password + "\n")
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("pure-pw useradd: %s: %w", strings.TrimSpace(string(out)), err)
	}
	return rebuildDB()
}

// DeleteAccount removes a Pure-FTPd virtual account.
func DeleteAccount(username string) error {
	if !reUsername.MatchString(username) {
		return fmt.Errorf("invalid FTP username: %s", username)
	}
	cmd := exec.Command("pure-pw", "userdel", username, "-f", passwdFile)
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("pure-pw userdel: %s: %w", strings.TrimSpace(string(out)), err)
	}
	return rebuildDB()
}

// ChangePassword updates the password of an existing Pure-FTPd virtual account.
func ChangePassword(username, password string) error {
	if !reUsername.MatchString(username) {
		return fmt.Errorf("invalid FTP username: %s", username)
	}
	cmd := exec.Command("pure-pw", "passwd", username, "-f", passwdFile)
	cmd.Stdin = strings.NewReader(password + "\n" + password + "\n")
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("pure-pw passwd: %s: %w", strings.TrimSpace(string(out)), err)
	}
	return rebuildDB()
}

// rebuildDB regenerates the binary PureDB from the passwd flat file.
func rebuildDB() error {
	out, err := exec.Command("pure-pw", "mkdb", purePwDB, "-f", passwdFile).CombinedOutput()
	if err != nil {
		return fmt.Errorf("pure-pw mkdb: %s: %w", strings.TrimSpace(string(out)), err)
	}
	return nil
}
