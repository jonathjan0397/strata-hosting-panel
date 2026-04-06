package account

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"

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
	if req.PHPVersion == "" || !isValidPHPVersion(req.PHPVersion) || !phpFPMAvailable(req.PHPVersion) {
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

	// userdel -r removes home directory too.
	// Clear lock before and after — Debian shadow-utils leaves it behind.
	clearPasswdLock()
	cmd := exec.Command("userdel", "-r", username)
	out, err := cmd.CombinedOutput()
	clearPasswdLock()
	if err != nil && !strings.Contains(string(out), "does not exist") {
		return fmt.Errorf("userdel: %w — %s", err, string(out))
	}

	return nil
}

func restartPHPFPM(version string) error {
	// Use reload (SIGUSR2) rather than restart — PHP-FPM handles new pool
	// configs gracefully without dropping in-flight requests.
	return exec.Command("systemctl", "reload", fmt.Sprintf("php%s-fpm", version)).Run()
}

// RemovePHPPool is a convenience wrapper used during deprovisioning.
func RemovePHPPool(username, phpVersion string) {
	php.RemovePool(username, phpVersion) //nolint:errcheck
}

// clearPasswdLock removes /etc/.pwd.lock if present. This Debian shadow-utils
// version has a bug where useradd/usermod/userdel leave the lock file behind
// after successful exit, causing subsequent callers to fail.
func clearPasswdLock() { os.Remove("/etc/.pwd.lock") } //nolint:errcheck

func createUser(username, homeDir string) error {
	// Always clear any stale lock before touching the user database.
	clearPasswdLock()

	// Create user only if they don't already exist.
	if _, err := exec.Command("id", username).Output(); err != nil {
		// Remove any stale group left by a prior interrupted useradd.
		exec.Command("groupdel", username).Run() //nolint:errcheck

		out, err2 := exec.Command("useradd",
			"--home-dir", homeDir,
			"--create-home",
			"--shell", "/sbin/nologin",
			"--user-group",
			username,
		).CombinedOutput()
		if err2 != nil {
			// Retry once: some Debian shadow-utils versions leave a stale lock
			// even after the previous caller exited cleanly.
			if strings.Contains(string(out), "cannot lock") {
				clearPasswdLock()
				time.Sleep(150 * time.Millisecond)
				out, err2 = exec.Command("useradd",
					"--home-dir", homeDir,
					"--create-home",
					"--shell", "/sbin/nologin",
					"--user-group",
					username,
				).CombinedOutput()
			}
			if err2 != nil {
				return fmt.Errorf("%w: %s", err2, strings.TrimSpace(string(out)))
			}
		}
		// useradd on this Debian version leaves the lock file — clear it.
		clearPasswdLock()
	}

	// Always ensure www-data is in the user's group so the web server can read files.
	clearPasswdLock()
	if out, err := exec.Command("usermod", "-aG", username, "www-data").CombinedOutput(); err != nil {
		return fmt.Errorf("usermod www-data: %w: %s", err, strings.TrimSpace(string(out)))
	}
	clearPasswdLock()
	return nil
}

func isValidPHPVersion(v string) bool {
	return v == "8.1" || v == "8.2" || v == "8.3" || v == "8.4"
}

// phpFPMAvailable returns true if php-fpmX.Y binary exists (i.e. the package is installed).
func phpFPMAvailable(ver string) bool {
	_, err := exec.LookPath(fmt.Sprintf("php-fpm%s", ver))
	return err == nil
}

// highestInstalledPHP returns the highest PHP version that has both the CLI
// binary and the FPM binary present.
func highestInstalledPHP() string {
	for _, ver := range []string{"8.4", "8.3", "8.2", "8.1"} {
		if phpFPMAvailable(ver) {
			return ver
		}
	}
	return "8.4" // last-resort default
}
