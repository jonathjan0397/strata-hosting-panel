package api

import (
	"errors"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"

	"github.com/go-chi/chi/v5"
)

type gitInitRequest struct {
	Path string `json:"path"`
}

type gitCloneRequest struct {
	Path      string `json:"path"`
	RemoteURL string `json:"remote_url"`
	Branch    string `json:"branch"`
}

type gitPullRequest struct {
	Path string `json:"path"`
}

func handleGitStatus(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	repoPath, err := resolveGitPath(username, r.URL.Query().Get("path"), false)
	if err != nil {
		status := http.StatusBadRequest
		if errors.Is(err, os.ErrNotExist) {
			status = http.StatusNotFound
		}
		http.Error(w, err.Error(), status)
		return
	}

	info, err := gitRepoStatus(username, repoPath)
	if err != nil {
		status := http.StatusUnprocessableEntity
		if errors.Is(err, os.ErrNotExist) {
			status = http.StatusNotFound
		}
		http.Error(w, err.Error(), status)
		return
	}

	respond(w, http.StatusOK, info)
}

func handleGitInit(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req gitInitRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	repoPath, err := resolveGitPath(username, req.Path, true)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	if err := os.MkdirAll(repoPath, 0755); err != nil {
		http.Error(w, "failed to create repository directory: "+err.Error(), http.StatusInternalServerError)
		return
	}

	if _, err := os.Stat(filepath.Join(repoPath, ".git")); err == nil {
		http.Error(w, "repository already initialized", http.StatusConflict)
		return
	}

	if _, err := runGit(repoPath, "init", "--initial-branch=main"); err != nil {
		if _, retryErr := runGit(repoPath, "init"); retryErr != nil {
			http.Error(w, "git init failed: "+retryErr.Error(), http.StatusUnprocessableEntity)
			return
		}
		_, _ = runGit(repoPath, "branch", "-M", "main")
	}

	info, err := gitRepoStatus(username, repoPath)
	if err != nil {
		http.Error(w, "repository initialized but status lookup failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, info)
}

func handleGitClone(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req gitCloneRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	repoPath, err := resolveGitPath(username, req.Path, true)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	if err := validateCloneURL(req.RemoteURL); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	parent := filepath.Dir(repoPath)
	if err := os.MkdirAll(parent, 0755); err != nil {
		http.Error(w, "failed to create repository parent directory: "+err.Error(), http.StatusInternalServerError)
		return
	}

	if entries, err := os.ReadDir(repoPath); err == nil && len(entries) > 0 {
		http.Error(w, "repository path already exists and is not empty", http.StatusConflict)
		return
	}

	args := []string{"clone", "--", req.RemoteURL, repoPath}
	if branch := strings.TrimSpace(req.Branch); branch != "" {
		args = []string{"clone", "--branch", branch, "--", req.RemoteURL, repoPath}
	}

	cmd := exec.Command("git", args...)
	output, err := cmd.CombinedOutput()
	if err != nil {
		http.Error(w, strings.TrimSpace(string(output)), http.StatusUnprocessableEntity)
		return
	}

	info, err := gitRepoStatus(username, repoPath)
	if err != nil {
		http.Error(w, "repository cloned but status lookup failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, info)
}

func handleGitPull(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req gitPullRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	repoPath, err := resolveGitPath(username, req.Path, false)
	if err != nil {
		status := http.StatusBadRequest
		if errors.Is(err, os.ErrNotExist) {
			status = http.StatusNotFound
		}
		http.Error(w, err.Error(), status)
		return
	}

	if _, err := runGit(repoPath, "pull", "--ff-only"); err != nil {
		http.Error(w, "git pull failed: "+err.Error(), http.StatusUnprocessableEntity)
		return
	}

	info, err := gitRepoStatus(username, repoPath)
	if err != nil {
		http.Error(w, "pull succeeded but status lookup failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, info)
}

func resolveGitPath(username, requestedPath string, allowMissing bool) (string, error) {
	if username == "" {
		return "", errors.New("username is required")
	}

	baseDir := filepath.Clean(filepath.Join("/var/www", username))
	if baseDir == "/" || baseDir == "." {
		return "", errors.New("invalid account base path")
	}

	if requestedPath == "" {
		requestedPath = "/public_html"
	}

	var candidate string
	if filepath.IsAbs(requestedPath) {
		candidate = filepath.Clean(requestedPath)
	} else {
		candidate = filepath.Clean(filepath.Join(baseDir, requestedPath))
	}

	if candidate == "/" || candidate == "." {
		return "", errors.New("invalid repository path")
	}
	if candidate != baseDir && !strings.HasPrefix(candidate, baseDir+string(os.PathSeparator)) {
		return "", errors.New("repository path must stay inside the account web root")
	}

	if !allowMissing {
		if _, err := os.Stat(candidate); err != nil {
			return "", err
		}
	}

	return candidate, nil
}

func validateCloneURL(raw string) error {
	remoteURL := strings.TrimSpace(raw)
	if remoteURL == "" {
		return errors.New("remote_url is required")
	}

	if strings.HasPrefix(remoteURL, "git@") {
		return nil
	}

	parsed, err := url.Parse(remoteURL)
	if err != nil {
		return errors.New("invalid remote_url")
	}

	if parsed.Scheme != "https" && parsed.Scheme != "http" && parsed.Scheme != "ssh" {
		return errors.New("remote_url must use https, http, ssh, or git@")
	}
	if parsed.Host == "" && parsed.Scheme != "ssh" {
		return errors.New("remote_url must include a host")
	}

	return nil
}

func gitRepoStatus(username, repoPath string) (map[string]any, error) {
	if _, err := os.Stat(repoPath); err != nil {
		return nil, err
	}

	topLevel, err := runGit(repoPath, "rev-parse", "--show-toplevel")
	if err != nil {
		return nil, errors.New("path is not a git repository")
	}

	relativePath := "/"
	if rel, relErr := filepath.Rel(filepath.Clean(filepath.Join("/var/www", username)), topLevel); relErr == nil && rel != "." {
		relativePath = "/" + filepath.ToSlash(rel)
	}

	branch := ""
	if value, err := runGit(topLevel, "symbolic-ref", "--quiet", "--short", "HEAD"); err == nil {
		branch = value
	} else if value, err := runGit(topLevel, "rev-parse", "--short", "HEAD"); err == nil {
		branch = value
	}

	remoteURL, _ := runGit(topLevel, "remote", "get-url", "origin")
	statusBranch, _ := runGit(topLevel, "status", "--short", "--branch")
	porcelain, _ := runGit(topLevel, "status", "--short")
	lastCommitHash, _ := runGit(topLevel, "log", "-1", "--pretty=format:%H")
	lastCommitSubject, _ := runGit(topLevel, "log", "-1", "--pretty=format:%s")
	lastCommitRelative, _ := runGit(topLevel, "log", "-1", "--pretty=format:%cr")

	ahead, behind := parseAheadBehind(statusBranch)
	changedFiles := 0
	if trimmed := strings.TrimSpace(porcelain); trimmed != "" {
		changedFiles = len(strings.Split(trimmed, "\n"))
	}

	return map[string]any{
		"path":          relativePath,
		"absolute_path": topLevel,
		"is_repo":       true,
		"branch":        branch,
		"remote_url":    remoteURL,
		"dirty":         changedFiles > 0,
		"changed_files": changedFiles,
		"ahead":         ahead,
		"behind":        behind,
		"last_commit": map[string]any{
			"hash":          lastCommitHash,
			"subject":       lastCommitSubject,
			"relative_time": lastCommitRelative,
		},
	}, nil
}

func runGit(repoPath string, args ...string) (string, error) {
	cmd := exec.Command("git", append([]string{"-C", repoPath}, args...)...)
	output, err := cmd.CombinedOutput()
	trimmed := strings.TrimSpace(string(output))
	if err != nil {
		if trimmed == "" {
			return "", err
		}
		return "", errors.New(trimmed)
	}

	return trimmed, nil
}

func parseAheadBehind(status string) (int, int) {
	line := strings.TrimSpace(strings.SplitN(status, "\n", 2)[0])
	start := strings.Index(line, "[")
	end := strings.Index(line, "]")
	if start == -1 || end == -1 || end <= start+1 {
		return 0, 0
	}

	var ahead int
	var behind int
	parts := strings.Split(line[start+1:end], ",")
	for _, part := range parts {
		part = strings.TrimSpace(part)
		switch {
		case strings.HasPrefix(part, "ahead "):
			ahead, _ = strconv.Atoi(strings.TrimSpace(strings.TrimPrefix(part, "ahead ")))
		case strings.HasPrefix(part, "behind "):
			behind, _ = strconv.Atoi(strings.TrimSpace(strings.TrimPrefix(part, "behind ")))
		}
	}

	return ahead, behind
}
