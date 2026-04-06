package account

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/jonathjan0397/strata-panel/agent/internal/php"
)

var validUsername = regexp.MustCompile(`^[a-z][a-z0-9_]{1,31}$`)

const (
	homeBase = "/home"
	wwwBase  = "/var/www"
)

type ProvisionRequest struct {
	Username   string `json:"username"`
	PHPVersion string `json:"php_version"` // "8.1", "8.2", "8.3"
}

type ProvisionResult struct {
	Username    string `json:"username"`
	HomeDir     string `json:"home_dir"`
	DocumentRoot string `json:"document_root"`
	PHPSocket   string `json:"php_socket"`
}

// Provision creates the system user, home directory, document root, and PHP-FPM pool.
func Provision(req ProvisionRequest) (*ProvisionResult, error) {
	if !validUsername.MatchString(req.Username) {
		return nil, fmt.Errorf("invalid username: must be lowercase alphanumeric/underscore, 2-32 chars, start with letter")
	}
	if req.PHPVersion == "" {
		req.PHPVersion = "8.3"
	}
	if !isValidPHPVersion(req.PHPVersion) {
		// Fall back to highest installed version rather than hard-failing
		req.PHPVersion = highestInstalledPHP()
	}

	homeDir     := filepath.Join(homeBase, req.Username)
	docRoot     := filepath.Join(wwwBase, req.Username, "public_html")
	phpSocket   := fmt.Sprintf("/run/php/php%s-fpm-%s.sock", req.PHPVersion, req.Username)

	// Create system user (no login shell, no password)
	if err := createUser(req.Username, homeDir); err != nil {
		return nil, fmt.Errorf("create user: %w", err)
	}

	// Create document root
	for _, dir := range []string{
		filepath.Join(wwwBase, req.Username),
		docRoot,
		filepath.Join(homeDir, "logs"),
		filepath.Join(homeDir, "tmp"),
	} {
		if err := os.MkdirAll(dir, 0750); err != nil {
			return nil, fmt.Errorf("mkdir %s: %w", dir, err)
		}
	}

	// chown to account user, group www-data for Nginx access
	if err := exec.Command("chown", "-R", req.Username+":www-data",
		filepath.Join(wwwBase, req.Username)).Run(); err != nil {
		return nil, fmt.Errorf("chown www: %w", err)
	}
	if err := exec.Command("chown", "-R", req.Username+":"+req.Username, homeDir).Run(); err != nil {
		return nil, fmt.Errorf("chown home: %w", err)
	}

	// PHP-FPM pool
	pool := php.DefaultPool(req.Username, req.PHPVersion)
	if err := php.WritePool(pool); err != nil {
		return nil, fmt.Errorf("php pool: %w", err)
	}
	if err := restartPHPFPM(req.PHPVersion); err != nil {
		return nil, fmt.Errorf("restart php-fpm: %w", err)
	}

	return &ProvisionResult{
		Username:     req.Username,
		HomeDir:      homeDir,
		DocumentRoot: docRoot,
		PHPSocket:    phpSocket,
	}, nil
}

// Deprovision removes the user, their files, and PHP-FPM pool.
func Deprovision(username string) error {
	if !validUsername.MatchString(username) {
		return fmt.Errorf("invalid username")
	}

	// Remove PHP pools for all versions
	for _, ver := range []string{"8.1", "8.2", "8.3", "8.4"} {
		RemovePHPPool(username, ver)
	}

	// Remove www directory
	wwwDir := filepath.Join(wwwBase, username)
	if err := os.RemoveAll(wwwDir); err != nil {
		return fmt.Errorf("remove www: %w", err)
	}

	// userdel -r removes home directory too
	cmd := exec.Command("userdel", "-r", username)
	out, err := cmd.CombinedOutput()
	if err != nil && !strings.Contains(string(out), "does not exist") {
		return fmt.Errorf("userdel: %w — %s", err, string(out))
	}

	return nil
}

func restartPHPFPM(version string) error {
	return exec.Command("systemctl", "reload-or-restart", fmt.Sprintf("php%s-fpm", version)).Run()
}

// RemovePHPPool is a convenience wrapper used during deprovisioning.
func RemovePHPPool(username, phpVersion string) {
	php.RemovePool(username, phpVersion) //nolint:errcheck
}

func createUser(username, homeDir string) error {
	// Create user only if they don't already exist.
	if _, err := exec.Command("id", username).Output(); err != nil {
		cmd := exec.Command("useradd",
			"--home-dir", homeDir,
			"--create-home",
			"--shell", "/sbin/nologin",
			"--user-group",
			username,
		)
		out, err := cmd.CombinedOutput()
		if err != nil {
			return fmt.Errorf("%w: %s", err, string(out))
		}
	}

	// Always ensure www-data is in the user's group so Nginx can read their files.
	if out, err := exec.Command("usermod", "-aG", username, "www-data").CombinedOutput(); err != nil {
		return fmt.Errorf("usermod www-data: %w: %s", err, string(out))
	}
	return nil
}

func isValidPHPVersion(v string) bool {
	return v == "8.1" || v == "8.2" || v == "8.3" || v == "8.4"
}

// highestInstalledPHP returns the highest PHP-FPM version with a running socket dir.
func highestInstalledPHP() string {
	for _, ver := range []string{"8.4", "8.3", "8.2", "8.1"} {
		if _, err := exec.LookPath(fmt.Sprintf("php%s", ver)); err == nil {
			return ver
		}
	}
	return "8.3" // last-resort default
}
