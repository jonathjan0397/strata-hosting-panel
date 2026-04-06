package backup

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

const (
	backupRoot = "/var/backups/strata"
	webRoot    = "/var/www"
	maxKeep    = 30 // maximum backups retained per account
)

// Entry describes a single backup archive on disk.
type Entry struct {
	Filename  string    `json:"filename"`
	Type      string    `json:"type"` // files | databases | full
	SizeBytes int64     `json:"size_bytes"`
	CreatedAt time.Time `json:"created_at"`
}

// Dir returns the per-account backup directory, creating it if needed.
func Dir(username string) (string, error) {
	dir := filepath.Join(backupRoot, username)
	if err := os.MkdirAll(dir, 0750); err != nil {
		return "", fmt.Errorf("mkdir %s: %w", dir, err)
	}
	return dir, nil
}

// Create runs a backup for username of the given type ("files", "databases", "full").
// Returns the resulting Entry on success.
func Create(username, backupType string) (*Entry, error) {
	dir, err := Dir(username)
	if err != nil {
		return nil, err
	}

	ts := time.Now().UTC().Format("20060102-150405")
	filename := fmt.Sprintf("%s_%s_%s.tar.gz", username, backupType, ts)
	dest := filepath.Join(dir, filename)

	switch backupType {
	case "files":
		if err := archiveFiles(username, dest); err != nil {
			return nil, err
		}
	case "databases":
		if err := dumpDatabases(username, dest); err != nil {
			return nil, err
		}
	case "full":
		// Create a temp dir, archive files and db dump into it, then tar the whole thing
		tmp, err := os.MkdirTemp("", "strata-backup-*")
		if err != nil {
			return nil, fmt.Errorf("tempdir: %w", err)
		}
		defer os.RemoveAll(tmp)

		if err := archiveFilesTo(username, filepath.Join(tmp, "files.tar.gz")); err != nil {
			return nil, err
		}
		if err := dumpDatabasesTo(username, filepath.Join(tmp, "databases.sql.gz")); err != nil {
			// Non-fatal — account may have no databases
			_ = err
		}
		if err := tarDir(tmp, dest); err != nil {
			return nil, err
		}
	default:
		return nil, fmt.Errorf("unknown backup type: %s", backupType)
	}

	info, err := os.Stat(dest)
	if err != nil {
		return nil, fmt.Errorf("stat backup: %w", err)
	}

	// Prune old backups
	_ = pruneOld(dir, username, backupType)

	return &Entry{
		Filename:  filename,
		Type:      backupType,
		SizeBytes: info.Size(),
		CreatedAt: time.Now().UTC(),
	}, nil
}

// List returns all backup entries for username, newest first.
func List(username string) ([]Entry, error) {
	dir := filepath.Join(backupRoot, username)
	entries, err := os.ReadDir(dir)
	if err != nil {
		if os.IsNotExist(err) {
			return []Entry{}, nil
		}
		return nil, err
	}

	var result []Entry
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".tar.gz") {
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		btype := "full"
		if strings.Contains(e.Name(), "_files_") {
			btype = "files"
		} else if strings.Contains(e.Name(), "_databases_") {
			btype = "databases"
		}
		result = append(result, Entry{
			Filename:  e.Name(),
			Type:      btype,
			SizeBytes: info.Size(),
			CreatedAt: info.ModTime().UTC(),
		})
	}

	sort.Slice(result, func(i, j int) bool {
		return result[i].CreatedAt.After(result[j].CreatedAt)
	})
	return result, nil
}

// Delete removes a backup file by filename. The filename is validated to stay
// within the account's backup directory.
func Delete(username, filename string) error {
	if strings.Contains(filename, "/") || strings.Contains(filename, "..") {
		return fmt.Errorf("invalid filename")
	}
	dir := filepath.Join(backupRoot, username)
	path := filepath.Join(dir, filename)
	return os.Remove(path)
}

// Path returns the absolute path to a backup file, validated within the
// account's backup directory. Returns error if the file doesn't exist or
// the path escapes the directory.
func Path(username, filename string) (string, error) {
	if strings.Contains(filename, "/") || strings.Contains(filename, "..") {
		return "", fmt.Errorf("invalid filename")
	}
	dir := filepath.Join(backupRoot, username)
	path := filepath.Join(dir, filename)
	if _, err := os.Stat(path); err != nil {
		return "", fmt.Errorf("backup not found: %s", filename)
	}
	return path, nil
}

// Restore restores a backup archive to the account's directories.
// backupType must be "files", "databases", or "full".
func Restore(username, backupPath, backupType string) error {
	switch backupType {
	case "files":
		return restoreFiles(backupPath)
	case "databases":
		return restoreDatabases(backupPath)
	case "full":
		// Full archive contains files.tar.gz and databases.sql.gz inside an outer tar.
		tmp, err := os.MkdirTemp("", "strata-restore-*")
		if err != nil {
			return fmt.Errorf("tempdir: %w", err)
		}
		defer os.RemoveAll(tmp)

		if out, err := exec.Command("tar", "-xzf", backupPath, "-C", tmp).CombinedOutput(); err != nil {
			return fmt.Errorf("extract outer tar: %w — %s", err, string(out))
		}

		if err := restoreFiles(filepath.Join(tmp, "files.tar.gz")); err != nil {
			return fmt.Errorf("restore files: %w", err)
		}
		dbDump := filepath.Join(tmp, "databases.sql.gz")
		if _, statErr := os.Stat(dbDump); statErr == nil {
			_ = restoreDatabases(dbDump) // non-fatal
		}
		return nil
	default:
		return fmt.Errorf("unknown backup type: %s", backupType)
	}
}

// RestorePath restores a single file or directory from a files/full backup into
// the account web root. sourceRel and targetRel are relative to /var/www/{user}.
func RestorePath(username, backupPath, backupType, sourceRel, targetRel string) error {
	if backupType == "databases" {
		return fmt.Errorf("path restore is only available for files and full backups")
	}

	sourceRel, err := cleanAccountRelativePath(sourceRel)
	if err != nil {
		return fmt.Errorf("invalid source path: %w", err)
	}
	if strings.TrimSpace(targetRel) == "" {
		targetRel = sourceRel
	}
	targetRel, err = cleanAccountRelativePath(targetRel)
	if err != nil {
		return fmt.Errorf("invalid target path: %w", err)
	}

	tmp, err := os.MkdirTemp("", "strata-path-restore-*")
	if err != nil {
		return fmt.Errorf("tempdir: %w", err)
	}
	defer os.RemoveAll(tmp)

	filesArchive := backupPath
	if backupType == "full" {
		if out, err := exec.Command("tar", "-xzf", backupPath, "-C", tmp).CombinedOutput(); err != nil {
			return fmt.Errorf("extract files archive: %w — %s", err, string(out))
		}
		filesArchive = filepath.Join(tmp, "files.tar.gz")
	}

	extracted := filepath.Join(tmp, "files")
	if err := os.MkdirAll(extracted, 0750); err != nil {
		return fmt.Errorf("mkdir extracted files: %w", err)
	}
	if out, err := exec.Command("tar", "-xzf", filesArchive, "-C", extracted).CombinedOutput(); err != nil {
		return fmt.Errorf("extract files: %w — %s", err, string(out))
	}

	sourceRoot := filepath.Join(extracted, username)
	source := filepath.Join(sourceRoot, sourceRel)
	if _, err := os.Stat(source); err != nil {
		return fmt.Errorf("source path not found in backup: %s", sourceRel)
	}
	source, err = filepath.EvalSymlinks(source)
	if err != nil {
		return fmt.Errorf("resolve source path: %w", err)
	}
	if !strings.HasPrefix(source, sourceRoot+string(os.PathSeparator)) && source != sourceRoot {
		return fmt.Errorf("source path escapes backup account root")
	}

	accountRoot := filepath.Join(webRoot, username)
	target := filepath.Join(accountRoot, targetRel)
	if !strings.HasPrefix(target, accountRoot+string(os.PathSeparator)) && target != accountRoot {
		return fmt.Errorf("target path escapes account root")
	}
	if err := rejectSymlinkPath(accountRoot, targetRel); err != nil {
		return err
	}

	if err := os.RemoveAll(target); err != nil {
		return fmt.Errorf("remove existing target: %w", err)
	}
	if err := copyRecursive(source, target); err != nil {
		return fmt.Errorf("copy restored path: %w", err)
	}
	_ = exec.Command("chown", "-R", username+":"+username, target).Run()

	return nil
}

// restoreFiles extracts a files.tar.gz back to /var/www (preserving the
// top-level username directory that was packed into the archive).
func restoreFiles(archivePath string) error {
	if _, err := os.Stat(archivePath); os.IsNotExist(err) {
		return fmt.Errorf("archive not found: %s", archivePath)
	}
	cmd := exec.Command("tar", "-xzf", archivePath, "-C", webRoot)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("tar extract: %w — %s", err, string(out))
	}
	return nil
}

func cleanAccountRelativePath(raw string) (string, error) {
	clean := filepath.Clean(strings.ReplaceAll(strings.TrimSpace(raw), "\\", "/"))
	if clean == "." || clean == "" {
		return "", fmt.Errorf("path is required")
	}
	if filepath.IsAbs(clean) || clean == ".." || strings.HasPrefix(clean, ".."+string(os.PathSeparator)) {
		return "", fmt.Errorf("path must stay inside the account")
	}
	return clean, nil
}

func rejectSymlinkPath(root, rel string) error {
	current := root
	for _, part := range strings.Split(rel, string(os.PathSeparator)) {
		if part == "" || part == "." {
			continue
		}
		current = filepath.Join(current, part)
		info, err := os.Lstat(current)
		if err != nil {
			if os.IsNotExist(err) {
				continue
			}
			return fmt.Errorf("inspect target path: %w", err)
		}
		if info.Mode()&os.ModeSymlink != 0 {
			return fmt.Errorf("refusing to restore through symlink target: %s", rel)
		}
	}
	return nil
}

func copyRecursive(src, dst string) error {
	info, err := os.Lstat(src)
	if err != nil {
		return err
	}
	if info.Mode()&os.ModeSymlink != 0 {
		return fmt.Errorf("refusing to restore symlink: %s", src)
	}
	if info.IsDir() {
		if err := os.MkdirAll(dst, info.Mode().Perm()); err != nil {
			return err
		}
		entries, err := os.ReadDir(src)
		if err != nil {
			return err
		}
		for _, entry := range entries {
			if err := copyRecursive(filepath.Join(src, entry.Name()), filepath.Join(dst, entry.Name())); err != nil {
				return err
			}
		}
		return nil
	}

	if err := os.MkdirAll(filepath.Dir(dst), 0750); err != nil {
		return err
	}
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	out, err := os.OpenFile(dst, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, info.Mode().Perm())
	if err != nil {
		return err
	}
	defer out.Close()

	_, err = io.Copy(out, in)
	return err
}

// restoreDatabases imports a gzip-compressed SQL dump via mysql.
func restoreDatabases(archivePath string) error {
	if _, err := os.Stat(archivePath); os.IsNotExist(err) {
		return fmt.Errorf("dump not found: %s", archivePath)
	}
	cmd := exec.Command("bash", "-c",
		fmt.Sprintf("zcat %s | mysql --defaults-file=/etc/strata-agent/mysql.cnf 2>/dev/null",
			archivePath))
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("mysql restore: %w — %s", err, string(out))
	}
	return nil
}

// ── Internal helpers ──────────────────────────────────────────────────────────

func archiveFiles(username, dest string) error {
	return archiveFilesTo(username, dest)
}

func archiveFilesTo(username, dest string) error {
	src := filepath.Join(webRoot, username)
	if _, err := os.Stat(src); os.IsNotExist(err) {
		return fmt.Errorf("web root not found: %s", src)
	}
	cmd := exec.Command("tar", "-czf", dest, "-C", filepath.Dir(src), filepath.Base(src))
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("tar: %w — %s", err, string(out))
	}
	return nil
}

func dumpDatabases(username, dest string) error {
	return dumpDatabasesTo(username, dest)
}

func dumpDatabasesTo(username, dest string) error {
	// Dump all databases whose name starts with the account username prefix.
	// Convention: databases are named {username}_{dbname}.
	cmd := exec.Command("bash", "-c",
		fmt.Sprintf(
			`mysqldump --defaults-file=/etc/strata-agent/mysql.cnf `+
				`--databases $(mysql --defaults-file=/etc/strata-agent/mysql.cnf -N -e `+
				`"SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE '%s_%%';" 2>/dev/null) `+
				`2>/dev/null | gzip > %s`,
			username, dest,
		),
	)
	// Non-fatal if no databases exist — an empty gzip is fine
	_ = cmd.Run()
	return nil
}

func tarDir(src, dest string) error {
	cmd := exec.Command("tar", "-czf", dest, "-C", src, ".")
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("tar dir: %w — %s", err, string(out))
	}
	return nil
}

func pruneOld(dir, username, backupType string) error {
	entries, err := List(username)
	if err != nil {
		return err
	}

	var matching []Entry
	for _, e := range entries {
		if e.Type == backupType {
			matching = append(matching, e)
		}
	}

	for i := maxKeep; i < len(matching); i++ {
		_ = os.Remove(filepath.Join(dir, matching[i].Filename))
	}
	return nil
}
