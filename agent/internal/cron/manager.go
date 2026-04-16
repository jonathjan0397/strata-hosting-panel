package cron

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
)

var validUsername = regexp.MustCompile(`^[a-z][a-z0-9_]{1,31}$`)

const (
	beginMarker = "# BEGIN STRATA MANAGED CRON"
	endMarker   = "# END STRATA MANAGED CRON"
)

type Job struct {
	Name       string `json:"name"`
	Expression string `json:"expression"`
	Command    string `json:"command"`
	Enabled    bool   `json:"is_enabled"`
}

func ApplyManaged(username string, jobs []Job) error {
	if !validUsername.MatchString(username) {
		return fmt.Errorf("invalid username")
	}

	existing, err := readCrontab(username)
	if err != nil {
		return err
	}

	cleaned := stripManagedBlock(existing)
	managed := renderManagedBlock(jobs)
	final := mergeCrontab(cleaned, managed)

	return installCrontab(username, final)
}

func readCrontab(username string) (string, error) {
	cmd := exec.Command("crontab", "-u", username, "-l")
	out, err := cmd.CombinedOutput()
	if err != nil {
		text := strings.ToLower(string(out))
		if strings.Contains(text, "no crontab for") || strings.TrimSpace(text) == "" {
			return "", nil
		}

		return "", fmt.Errorf("read crontab: %w: %s", err, strings.TrimSpace(string(out)))
	}

	return string(out), nil
}

func stripManagedBlock(content string) string {
	lines := strings.Split(strings.ReplaceAll(content, "\r\n", "\n"), "\n")
	filtered := make([]string, 0, len(lines))
	inManaged := false

	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		switch trimmed {
		case beginMarker:
			inManaged = true
			continue
		case endMarker:
			inManaged = false
			continue
		}

		if !inManaged {
			filtered = append(filtered, line)
		}
	}

	return strings.TrimRight(strings.Join(filtered, "\n"), "\n")
}

func renderManagedBlock(jobs []Job) string {
	lines := []string{
		beginMarker,
		"# Managed by Strata Hosting Panel.",
		"SHELL=/bin/bash",
		"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
	}

	count := 0
	for _, job := range jobs {
		if !job.Enabled {
			continue
		}

		expression := strings.TrimSpace(job.Expression)
		command := strings.TrimSpace(job.Command)
		if expression == "" || command == "" {
			continue
		}

		if job.Name != "" {
			lines = append(lines, "# "+strings.TrimSpace(job.Name))
		}

		lines = append(lines, expression+" "+command)
		count++
	}

	if count == 0 {
		return ""
	}

	lines = append(lines, endMarker)

	return strings.Join(lines, "\n")
}

func mergeCrontab(existing, managed string) string {
	parts := make([]string, 0, 2)
	existing = strings.TrimSpace(existing)
	managed = strings.TrimSpace(managed)

	if existing != "" {
		parts = append(parts, existing)
	}
	if managed != "" {
		parts = append(parts, managed)
	}

	if len(parts) == 0 {
		return ""
	}

	return strings.Join(parts, "\n\n") + "\n"
}

func installCrontab(username, content string) error {
	if strings.TrimSpace(content) == "" {
		cmd := exec.Command("crontab", "-u", username, "-r")
		out, err := cmd.CombinedOutput()
		if err != nil {
			text := strings.ToLower(string(out))
			if strings.Contains(text, "no crontab for") || strings.TrimSpace(text) == "" {
				return nil
			}

			return fmt.Errorf("remove crontab: %w: %s", err, strings.TrimSpace(string(out)))
		}

		return nil
	}

	tmp, err := os.CreateTemp("", "strata-cron-*.tab")
	if err != nil {
		return fmt.Errorf("create temp crontab: %w", err)
	}
	tmpPath := tmp.Name()
	defer os.Remove(tmpPath) //nolint:errcheck

	if _, err := tmp.WriteString(content); err != nil {
		tmp.Close() //nolint:errcheck
		return fmt.Errorf("write temp crontab: %w", err)
	}

	if err := tmp.Close(); err != nil {
		return fmt.Errorf("close temp crontab: %w", err)
	}

	if err := os.Chmod(tmpPath, 0600); err != nil {
		return fmt.Errorf("chmod temp crontab: %w", err)
	}

	cmd := exec.Command("crontab", "-u", username, filepath.Clean(tmpPath))
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("install crontab: %w: %s", err, strings.TrimSpace(string(out)))
	}

	return nil
}
