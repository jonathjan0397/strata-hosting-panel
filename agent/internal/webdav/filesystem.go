package webdav

import (
	"context"
	"fmt"
	"os"
	"path"
	"path/filepath"
	"strings"

	xwebdav "golang.org/x/net/webdav"
)

// SafeFileSystem keeps WebDAV operations inside a single account root and
// resolves symlinks before touching the filesystem.
type SafeFileSystem struct {
	root string
}

func NewFileSystem(root string) (*SafeFileSystem, error) {
	root = filepath.Clean(root)
	resolved, err := filepath.EvalSymlinks(root)
	if err != nil {
		return nil, err
	}
	resolved = filepath.Clean(resolved)
	if !strings.HasPrefix(resolved, "/var/www/") || resolved == "/var/www" {
		return nil, fmt.Errorf("invalid WebDAV root: %s", resolved)
	}
	return &SafeFileSystem{root: resolved}, nil
}

func (fs *SafeFileSystem) Mkdir(_ context.Context, name string, perm os.FileMode) error {
	target, err := fs.resolveForCreate(name)
	if err != nil {
		return err
	}
	return os.Mkdir(target, perm)
}

func (fs *SafeFileSystem) OpenFile(_ context.Context, name string, flag int, perm os.FileMode) (xwebdav.File, error) {
	target, err := fs.resolve(name)
	if os.IsNotExist(err) && flag&os.O_CREATE != 0 {
		target, err = fs.resolveForCreate(name)
	}
	if err != nil {
		return nil, err
	}
	return os.OpenFile(target, flag, perm)
}

func (fs *SafeFileSystem) RemoveAll(_ context.Context, name string) error {
	target, err := fs.resolve(name)
	if err != nil {
		return err
	}
	if target == fs.root {
		return fmt.Errorf("cannot remove WebDAV root")
	}
	return os.RemoveAll(target)
}

func (fs *SafeFileSystem) Rename(_ context.Context, oldName, newName string) error {
	oldTarget, err := fs.resolve(oldName)
	if err != nil {
		return err
	}
	newTarget, err := fs.resolveForCreate(newName)
	if err != nil {
		return err
	}
	if oldTarget == fs.root || newTarget == fs.root {
		return fmt.Errorf("cannot rename WebDAV root")
	}
	return os.Rename(oldTarget, newTarget)
}

func (fs *SafeFileSystem) Stat(_ context.Context, name string) (os.FileInfo, error) {
	target, err := fs.resolve(name)
	if err != nil {
		return nil, err
	}
	return os.Stat(target)
}

func (fs *SafeFileSystem) resolve(name string) (string, error) {
	candidate := fs.candidate(name)
	resolved, err := filepath.EvalSymlinks(candidate)
	if err != nil {
		return "", err
	}
	resolved = filepath.Clean(resolved)
	if !fs.inside(resolved) {
		return "", fmt.Errorf("path escapes WebDAV root")
	}
	return resolved, nil
}

func (fs *SafeFileSystem) resolveForCreate(name string) (string, error) {
	candidate := fs.candidate(name)
	parent := filepath.Dir(candidate)
	resolvedParent, err := filepath.EvalSymlinks(parent)
	if err != nil {
		return "", err
	}
	resolvedParent = filepath.Clean(resolvedParent)
	if !fs.inside(resolvedParent) {
		return "", fmt.Errorf("path escapes WebDAV root")
	}
	return filepath.Join(resolvedParent, filepath.Base(candidate)), nil
}

func (fs *SafeFileSystem) candidate(name string) string {
	clean := path.Clean("/" + strings.TrimLeft(name, "/"))
	rel := strings.TrimPrefix(clean, "/")
	return filepath.Join(fs.root, filepath.FromSlash(rel))
}

func (fs *SafeFileSystem) inside(target string) bool {
	return target == fs.root || strings.HasPrefix(target, fs.root+string(os.PathSeparator))
}
