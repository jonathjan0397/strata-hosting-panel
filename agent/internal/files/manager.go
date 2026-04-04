package files

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

const wwwBase = "/var/www"

// JailRoot returns the root directory for a given username.
func JailRoot(username string) string {
	return filepath.Join(wwwBase, username)
}

// Resolve resolves a relative path within the jail and returns the absolute path.
// Returns an error if the resolved path escapes the jail.
func Resolve(username, rel string) (string, error) {
	root := JailRoot(username)
	clean := filepath.Join(root, filepath.Clean("/"+rel))
	if !strings.HasPrefix(clean, root+"/") && clean != root {
		return "", fmt.Errorf("path escapes jail")
	}
	return clean, nil
}

// Entry represents a single directory entry.
type Entry struct {
	Name    string    `json:"name"`
	Path    string    `json:"path"`    // relative to jail root
	IsDir   bool      `json:"is_dir"`
	Size    int64     `json:"size"`
	Mode    string    `json:"mode"`    // e.g. "0755"
	ModTime time.Time `json:"mod_time"`
}

// List returns the contents of a directory (non-recursive).
func List(username, relPath string) ([]Entry, error) {
	abs, err := Resolve(username, relPath)
	if err != nil {
		return nil, err
	}

	entries, err := os.ReadDir(abs)
	if err != nil {
		return nil, err
	}

	root := JailRoot(username)
	result := make([]Entry, 0, len(entries))

	for _, e := range entries {
		info, err := e.Info()
		if err != nil {
			continue
		}
		absEntry := filepath.Join(abs, e.Name())
		rel, _ := filepath.Rel(root, absEntry)
		result = append(result, Entry{
			Name:    e.Name(),
			Path:    "/" + rel,
			IsDir:   e.IsDir(),
			Size:    info.Size(),
			Mode:    fmt.Sprintf("%04o", info.Mode().Perm()),
			ModTime: info.ModTime(),
		})
	}

	// Directories first, then files, both alpha
	sort.Slice(result, func(i, j int) bool {
		if result[i].IsDir != result[j].IsDir {
			return result[i].IsDir
		}
		return result[i].Name < result[j].Name
	})

	return result, nil
}

// ReadFile returns the content of a text file (capped at 2 MB).
func ReadFile(username, relPath string) ([]byte, error) {
	abs, err := Resolve(username, relPath)
	if err != nil {
		return nil, err
	}

	info, err := os.Stat(abs)
	if err != nil {
		return nil, err
	}
	if info.IsDir() {
		return nil, fmt.Errorf("path is a directory")
	}
	if info.Size() > 2<<20 {
		return nil, fmt.Errorf("file too large for inline editing (>2 MB)")
	}

	return os.ReadFile(abs)
}

// WriteFile writes (creates or overwrites) a file.
func WriteFile(username, relPath string, content []byte) error {
	abs, err := Resolve(username, relPath)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(abs), 0755); err != nil {
		return err
	}
	return os.WriteFile(abs, content, 0644)
}

// MkDir creates a directory (and parents).
func MkDir(username, relPath string) error {
	abs, err := Resolve(username, relPath)
	if err != nil {
		return err
	}
	return os.MkdirAll(abs, 0755)
}

// Rename moves/renames a file or directory within the jail.
func Rename(username, relFrom, relTo string) error {
	absFrom, err := Resolve(username, relFrom)
	if err != nil {
		return err
	}
	absTo, err := Resolve(username, relTo)
	if err != nil {
		return err
	}
	return os.Rename(absFrom, absTo)
}

// Delete removes a file or directory (recursive for dirs).
func Delete(username, relPath string) error {
	abs, err := Resolve(username, relPath)
	if err != nil {
		return err
	}
	root := JailRoot(username)
	if abs == root {
		return fmt.Errorf("cannot delete jail root")
	}
	return os.RemoveAll(abs)
}

// Chmod changes permissions on a file or directory.
func Chmod(username, relPath string, mode os.FileMode) error {
	abs, err := Resolve(username, relPath)
	if err != nil {
		return err
	}
	return os.Chmod(abs, mode)
}

// Compress creates a zip or tar.gz archive from the given relative paths.
// dest is the output archive path relative to the jail.
func Compress(username string, relPaths []string, relDest, format string) error {
	dest, err := Resolve(username, relDest)
	if err != nil {
		return err
	}

	// Collect absolute sources
	sources := make([]string, 0, len(relPaths))
	for _, rp := range relPaths {
		abs, err := Resolve(username, rp)
		if err != nil {
			return fmt.Errorf("invalid path %q: %w", rp, err)
		}
		sources = append(sources, abs)
	}

	switch format {
	case "zip":
		return createZip(sources, dest)
	case "tar.gz", "tgz":
		return createTarGz(sources, dest)
	default:
		return fmt.Errorf("unsupported format: %s (use zip or tar.gz)", format)
	}
}

// Extract unpacks a zip or tar.gz archive into relDest.
func Extract(username, relPath, relDest string) error {
	src, err := Resolve(username, relPath)
	if err != nil {
		return err
	}
	dest, err := Resolve(username, relDest)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dest, 0755); err != nil {
		return err
	}

	lower := strings.ToLower(relPath)
	switch {
	case strings.HasSuffix(lower, ".zip"):
		return extractZip(src, dest, username)
	case strings.HasSuffix(lower, ".tar.gz") || strings.HasSuffix(lower, ".tgz"):
		return extractTarGz(src, dest, username)
	default:
		return fmt.Errorf("unsupported archive type")
	}
}

// ── zip helpers ───────────────────────────────────────────────────────────────

func createZip(sources []string, dest string) error {
	f, err := os.Create(dest)
	if err != nil {
		return err
	}
	defer f.Close()

	w := zip.NewWriter(f)
	defer w.Close()

	for _, src := range sources {
		if err := addToZip(w, src, filepath.Dir(src)); err != nil {
			return err
		}
	}
	return nil
}

func addToZip(w *zip.Writer, path, base string) error {
	return filepath.Walk(path, func(p string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(base, p)
		if info.IsDir() {
			_, err = w.Create(rel + "/")
			return err
		}
		entry, err := w.Create(rel)
		if err != nil {
			return err
		}
		f, err := os.Open(p)
		if err != nil {
			return err
		}
		defer f.Close()
		_, err = io.Copy(entry, f)
		return err
	})
}

func extractZip(src, dest, username string) error {
	r, err := zip.OpenReader(src)
	if err != nil {
		return err
	}
	defer r.Close()

	root := JailRoot(username)

	for _, f := range r.File {
		target := filepath.Join(dest, filepath.Clean("/"+f.Name))
		// Security: ensure target stays within jail
		if !strings.HasPrefix(target, root) {
			return fmt.Errorf("zip slip detected: %s", f.Name)
		}
		if f.FileInfo().IsDir() {
			os.MkdirAll(target, 0755)
			continue
		}
		if err := os.MkdirAll(filepath.Dir(target), 0755); err != nil {
			return err
		}
		out, err := os.Create(target)
		if err != nil {
			return err
		}
		rc, err := f.Open()
		if err != nil {
			out.Close()
			return err
		}
		_, err = io.Copy(out, rc)
		rc.Close()
		out.Close()
		if err != nil {
			return err
		}
	}
	return nil
}

// ── tar.gz helpers ────────────────────────────────────────────────────────────

func createTarGz(sources []string, dest string) error {
	f, err := os.Create(dest)
	if err != nil {
		return err
	}
	defer f.Close()

	gz := gzip.NewWriter(f)
	defer gz.Close()

	tw := tar.NewWriter(gz)
	defer tw.Close()

	for _, src := range sources {
		if err := addToTar(tw, src, filepath.Dir(src)); err != nil {
			return err
		}
	}
	return nil
}

func addToTar(tw *tar.Writer, path, base string) error {
	return filepath.Walk(path, func(p string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(base, p)
		hdr, err := tar.FileInfoHeader(info, "")
		if err != nil {
			return err
		}
		hdr.Name = rel
		if err := tw.WriteHeader(hdr); err != nil {
			return err
		}
		if info.IsDir() {
			return nil
		}
		f, err := os.Open(p)
		if err != nil {
			return err
		}
		defer f.Close()
		_, err = io.Copy(tw, f)
		return err
	})
}

func extractTarGz(src, dest, username string) error {
	f, err := os.Open(src)
	if err != nil {
		return err
	}
	defer f.Close()

	gz, err := gzip.NewReader(f)
	if err != nil {
		return err
	}
	defer gz.Close()

	root := JailRoot(username)
	tr := tar.NewReader(gz)

	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return err
		}

		target := filepath.Join(dest, filepath.Clean("/"+hdr.Name))
		if !strings.HasPrefix(target, root) {
			return fmt.Errorf("tar slip detected: %s", hdr.Name)
		}

		if hdr.FileInfo().IsDir() {
			os.MkdirAll(target, 0755)
			continue
		}
		if err := os.MkdirAll(filepath.Dir(target), 0755); err != nil {
			return err
		}
		out, err := os.Create(target)
		if err != nil {
			return err
		}
		_, err = io.Copy(out, tr)
		out.Close()
		if err != nil {
			return err
		}
	}
	return nil
}

// OpenForDownload returns a ReadCloser for streaming a file to the client.
func OpenForDownload(username, relPath string) (*os.File, os.FileInfo, error) {
	abs, err := Resolve(username, relPath)
	if err != nil {
		return nil, nil, err
	}
	info, err := os.Stat(abs)
	if err != nil {
		return nil, nil, err
	}
	if info.IsDir() {
		return nil, nil, fmt.Errorf("cannot download a directory; compress it first")
	}
	f, err := os.Open(abs)
	return f, info, err
}

// SaveUpload writes uploaded bytes to a path within the jail.
func SaveUpload(username, relDir, filename string, r io.Reader) error {
	dir, err := Resolve(username, relDir)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0755); err != nil {
		return err
	}
	// Strip any directory component from the filename for safety.
	safe := filepath.Base(filename)
	if safe == "." || safe == "/" {
		return fmt.Errorf("invalid filename")
	}
	dest := filepath.Join(dir, safe)
	// dest must still be inside jail
	root := JailRoot(username)
	if !strings.HasPrefix(dest, root) {
		return fmt.Errorf("path escapes jail")
	}

	f, err := os.Create(dest)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = io.Copy(f, r)
	return err
}

// ChownToUser restores ownership of a path to the system user after agent writes.
func ChownToUser(username, relPath string) {
	abs, err := Resolve(username, relPath)
	if err != nil {
		return
	}
	exec.Command("chown", "-R", username+":www-data", abs).Run() //nolint:errcheck
}
