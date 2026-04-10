package api

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

type appInstallRequest struct {
	App        string `json:"app"`
	InstallDir string `json:"install_dir"`
	DBName     string `json:"db_name"`
	DBUser     string `json:"db_user"`
	DBPassword string `json:"db_password"`
	SiteURL    string `json:"site_url"`
	SiteTitle  string `json:"site_title"`
	AdminEmail string `json:"admin_email"`
	SiteOwner  string `json:"site_owner"`
}

type appUpdateRequest struct {
	App        string `json:"app"`
	InstallDir string `json:"install_dir"`
	DBName     string `json:"db_name"`
	DBUser     string `json:"db_user"`
	DBPassword string `json:"db_password"`
	SiteOwner  string `json:"site_owner"`
}

type appUninstallRequest struct {
	InstallDir string `json:"install_dir"`
	DBName     string `json:"db_name"`
	DBUser     string `json:"db_user"`
	SiteOwner  string `json:"site_owner"`
}

// POST /v1/apps/install
func handleAppInstall(w http.ResponseWriter, r *http.Request) {
	var req appInstallRequest
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.App == "" || req.InstallDir == "" {
		http.Error(w, "app and install_dir are required", http.StatusBadRequest)
		return
	}
	if !isSafeInstallDir(req.InstallDir, req.SiteOwner) {
		http.Error(w, "invalid install_dir", http.StatusBadRequest)
		return
	}

	if err := os.MkdirAll(req.InstallDir, 0755); err != nil {
		http.Error(w, "failed to create install directory: "+err.Error(), http.StatusInternalServerError)
		return
	}

	// Create DB + user
	if req.DBName != "" {
		if err := createAppDatabase(req.DBName, req.DBUser, req.DBPassword); err != nil {
			http.Error(w, "database setup failed: "+err.Error(), http.StatusInternalServerError)
			return
		}
	}

	var version, setupURL string
	var installErr error

	switch req.App {
	case "wordpress":
		version, installErr = installWordPress(req)
	default:
		setupURL, installErr = installGenericApp(req)
	}

	if installErr != nil {
		respond(w, http.StatusUnprocessableEntity, map[string]string{
			"error": installErr.Error(),
		})
		return
	}

	// Fix ownership
	if req.SiteOwner != "" {
		exec.Command("chown", "-R", req.SiteOwner+":www-data", req.InstallDir).Run()
	}

	result := map[string]string{"status": "installed"}
	if version != "" {
		result["version"] = version
	}
	if setupURL != "" {
		result["setup_url"] = req.SiteURL + strings.TrimPrefix(setupURL, "/")
	}
	respond(w, http.StatusOK, result)
}

// POST /v1/apps/update
func handleAppUpdate(w http.ResponseWriter, r *http.Request) {
	var req appUpdateRequest
	if !decodeJSON(w, r, &req) {
		return
	}
	if !isSafeInstallDir(req.InstallDir, req.SiteOwner) {
		http.Error(w, "invalid install_dir", http.StatusBadRequest)
		return
	}

	var version string
	var err error

	switch req.App {
	case "wordpress":
		version, err = updateWordPress(req)
	default:
		err = fmt.Errorf("auto-update not supported for %s — update via web admin panel", req.App)
	}

	if err != nil {
		respond(w, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})
		return
	}

	if req.SiteOwner != "" {
		exec.Command("chown", "-R", req.SiteOwner+":www-data", req.InstallDir).Run()
	}

	result := map[string]string{"status": "updated"}
	if version != "" {
		result["version"] = version
	}
	respond(w, http.StatusOK, result)
}

// DELETE /v1/apps/uninstall
func handleAppUninstall(w http.ResponseWriter, r *http.Request) {
	var req appUninstallRequest
	if !decodeJSON(w, r, &req) {
		return
	}
	if !isSafeInstallDir(req.InstallDir, req.SiteOwner) {
		http.Error(w, "invalid install_dir", http.StatusBadRequest)
		return
	}

	// Remove files
	if req.InstallDir != "" && req.InstallDir != "/" {
		os.RemoveAll(req.InstallDir)
	}

	// Drop DB + user
	if req.DBName != "" {
		dropAppDatabase(req.DBName, req.DBUser)
	}

	respond(w, http.StatusOK, map[string]string{"status": "uninstalled"})
}

// ── WordPress (WP-CLI) ────────────────────────────────────────────────────────

func ensureWPCLI() error {
	if _, err := exec.LookPath("wp"); err == nil {
		return nil
	}
	out, err := exec.Command("bash", "-c",
		`curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp`,
	).CombinedOutput()
	if err != nil {
		return fmt.Errorf("WP-CLI install failed: %s", strings.TrimSpace(string(out)))
	}
	return nil
}

func installWordPress(req appInstallRequest) (string, error) {
	if err := ensureWPCLI(); err != nil {
		return "", err
	}
	dir := req.InstallDir

	wp := func(args ...string) (string, error) {
		base := []string{"--path=" + dir, "--allow-root"}
		cmd := exec.Command("wp", append(base, args...)...)
		out, err := cmd.CombinedOutput()
		return strings.TrimSpace(string(out)), err
	}

	// Download core
	if out, err := wp("core", "download", "--skip-content"); err != nil {
		return "", fmt.Errorf("wp core download: %s", out)
	}

	// Create config
	if out, err := wp("config", "create",
		"--dbname="+req.DBName,
		"--dbuser="+req.DBUser,
		"--dbpass="+req.DBPassword,
		"--dbhost=127.0.0.1",
		"--skip-check",
	); err != nil {
		return "", fmt.Errorf("wp config create: %s", out)
	}

	// Harden config immediately
	wp("config", "set", "DISALLOW_FILE_EDIT", "true", "--raw")
	wp("config", "set", "WP_DEBUG", "false", "--raw")
	wp("config", "set", "WP_DEBUG_LOG", "false", "--raw")

	// Generate a secure admin password (not stored — user sets via email)
	adminPass := generateRandomHex(12)

	// Install WordPress
	adminUser := "admin"
	if out, err := wp("core", "install",
		"--url="+req.SiteURL,
		"--title="+req.SiteTitle,
		"--admin_user="+adminUser,
		"--admin_email="+req.AdminEmail,
		"--admin_password="+adminPass,
	); err != nil {
		return "", fmt.Errorf("wp core install: %s", out)
	}

	// Install Akismet (spam protection) and Hello Dolly-free setup
	wp("plugin", "delete", "hello")

	// Harden file permissions
	hardenWordPressPermissions(dir)

	// Get installed version
	version, _ := wp("core", "version")

	return version, nil
}

func updateWordPress(req appUpdateRequest) (string, error) {
	if err := ensureWPCLI(); err != nil {
		return "", err
	}
	dir := req.InstallDir

	wp := func(args ...string) (string, error) {
		base := []string{"--path=" + dir, "--allow-root"}
		cmd := exec.Command("wp", append(base, args...)...)
		out, err := cmd.CombinedOutput()
		return strings.TrimSpace(string(out)), err
	}

	// Update core, plugins, themes
	wp("core", "update")
	wp("core", "update-db")
	wp("plugin", "update", "--all")
	wp("theme", "update", "--all")

	// Re-harden after update
	hardenWordPressPermissions(dir)

	version, _ := wp("core", "version")
	return version, nil
}

func hardenWordPressPermissions(dir string) {
	// Directories 755, files 644, wp-config.php 600
	exec.Command("find", dir, "-type", "d", "-exec", "chmod", "755", "{}", "+").Run()
	exec.Command("find", dir, "-type", "f", "-exec", "chmod", "644", "{}", "+").Run()
	wpConfig := filepath.Join(dir, "wp-config.php")
	if _, err := os.Stat(wpConfig); err == nil {
		os.Chmod(wpConfig, 0600)
	}
	// Deny PHP execution in uploads
	uploadsDir := filepath.Join(dir, "wp-content", "uploads")
	htaccess := filepath.Join(uploadsDir, ".htaccess")
	if _, err := os.Stat(uploadsDir); err == nil {
		os.WriteFile(htaccess, []byte("php_flag engine off\n<FilesMatch \"\\.php$\">\ndeny from all\n</FilesMatch>\n"), 0644)
	}
}

// ── Generic assisted install ──────────────────────────────────────────────────

var genericDownloadURLs = map[string]string{
	"joomla": "https://downloads.joomla.org/cms/joomla5/5-3-0/Joomla_5.3.0-Stable-Full_Package.zip",
	"drupal": "https://www.drupal.org/download-latest/tar.gz",
	"piwigo": "https://piwigo.org/download/dlcounter.php?code=latest",
	"phpbb":  "https://download.phpbb.com/pub/release/3.3/3.3.12/phpBB-3.3.12.zip",
}

var genericSetupPaths = map[string]string{
	"joomla": "installation/",
	"drupal": "core/install.php",
	"piwigo": "install.php",
	"phpbb":  "install/",
}

func installGenericApp(req appInstallRequest) (string, error) {
	downloadURL, ok := genericDownloadURLs[req.App]
	if !ok {
		return "", fmt.Errorf("unknown app: %s", req.App)
	}

	// Download archive
	tmpFile := fmt.Sprintf("/tmp/strata_app_%s.zip", req.App)
	curlCmd := exec.Command("curl", "-fsSL", "-o", tmpFile, downloadURL)
	if out, err := curlCmd.CombinedOutput(); err != nil {
		// Try tar.gz if zip failed (Drupal uses tar.gz)
		tmpFile = fmt.Sprintf("/tmp/strata_app_%s.tar.gz", req.App)
		curlCmd = exec.Command("curl", "-fsSL", "-L", "-o", tmpFile, downloadURL)
		if out2, err2 := curlCmd.CombinedOutput(); err2 != nil {
			return "", fmt.Errorf("download failed: %s / %s", string(out), string(out2))
		}
	}
	defer os.Remove(tmpFile)

	// Extract
	if strings.HasSuffix(tmpFile, ".tar.gz") {
		if out, err := exec.Command("tar", "-xzf", tmpFile, "-C", req.InstallDir, "--strip-components=1").CombinedOutput(); err != nil {
			return "", fmt.Errorf("extract failed: %s", string(out))
		}
	} else {
		tmpDir := "/tmp/strata_app_extract"
		os.MkdirAll(tmpDir, 0755)
		defer os.RemoveAll(tmpDir)
		if out, err := exec.Command("unzip", "-q", tmpFile, "-d", tmpDir).CombinedOutput(); err != nil {
			return "", fmt.Errorf("unzip failed: %s", string(out))
		}
		// Move contents (handle single top-level directory in zip)
		entries, _ := os.ReadDir(tmpDir)
		src := tmpDir
		if len(entries) == 1 && entries[0].IsDir() {
			src = filepath.Join(tmpDir, entries[0].Name())
		}
		if out, err := exec.Command("sh", "-c", fmt.Sprintf("cp -r %s/. %s/", src, req.InstallDir)).CombinedOutput(); err != nil {
			return "", fmt.Errorf("copy failed: %s", string(out))
		}
	}

	// Set permissions
	exec.Command("find", req.InstallDir, "-type", "d", "-exec", "chmod", "755", "{}", "+").Run()
	exec.Command("find", req.InstallDir, "-type", "f", "-exec", "chmod", "644", "{}", "+").Run()

	setupPath := genericSetupPaths[req.App]
	return "/" + setupPath, nil
}

// ── Database helpers ──────────────────────────────────────────────────────────

func createAppDatabase(dbName, dbUser, dbPass string) error {
	mysql := func(query string) error {
		cmd := exec.Command("mysql",
			"--defaults-file=/etc/strata-agent/mysql.cnf",
			"-h", "127.0.0.1",
			"-e", query,
		)
		out, err := cmd.CombinedOutput()
		if err != nil {
			return fmt.Errorf("%s: %s", query[:min(30, len(query))], strings.TrimSpace(string(out)))
		}
		return nil
	}

	if err := mysql(fmt.Sprintf("CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;", dbName)); err != nil {
		return err
	}
	mysql(fmt.Sprintf("CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';", dbUser, dbPass))
	mysql(fmt.Sprintf("ALTER USER '%s'@'localhost' IDENTIFIED BY '%s';", dbUser, dbPass))
	mysql(fmt.Sprintf("CREATE USER IF NOT EXISTS '%s'@'127.0.0.1' IDENTIFIED BY '%s';", dbUser, dbPass))
	mysql(fmt.Sprintf("ALTER USER '%s'@'127.0.0.1' IDENTIFIED BY '%s';", dbUser, dbPass))
	mysql(fmt.Sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'localhost';", dbName, dbUser))
	mysql(fmt.Sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'127.0.0.1';", dbName, dbUser))
	mysql("FLUSH PRIVILEGES;")
	return nil
}

func dropAppDatabase(dbName, dbUser string) {
	mysql := func(query string) {
		exec.Command("mysql",
			"--defaults-file=/etc/strata-agent/mysql.cnf",
			"-h", "127.0.0.1",
			"-e", query,
		).Run()
	}
	mysql(fmt.Sprintf("DROP DATABASE IF EXISTS `%s`;", dbName))
	mysql(fmt.Sprintf("DROP USER IF EXISTS '%s'@'localhost';", dbUser))
	mysql(fmt.Sprintf("DROP USER IF EXISTS '%s'@'127.0.0.1';", dbUser))
	mysql("FLUSH PRIVILEGES;")
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func generateRandomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func isSafeInstallDir(installDir, siteOwner string) bool {
	if installDir == "" || siteOwner == "" {
		return false
	}
	if strings.Contains(installDir, "..") {
		return false
	}

	cleanDir := filepath.Clean(installDir)
	baseDir := filepath.Clean(filepath.Join("/var/www", siteOwner))
	if cleanDir == "/" || cleanDir == "." || baseDir == "/" {
		return false
	}

	return cleanDir == baseDir || strings.HasPrefix(cleanDir, baseDir+"/")
}
