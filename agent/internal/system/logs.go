package system

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

// allowedLogs maps a friendly service name to its log path.
// Only these paths are ever readable — no traversal possible.
var allowedLogs = map[string]string{
	"nginx":        "/var/log/nginx/error.log",
	"nginx-access": "/var/log/nginx/access.log",
	"php8.1-fpm":   "/var/log/php8.1-fpm.log",
	"php8.2-fpm":   "/var/log/php8.2-fpm.log",
	"php8.3-fpm":   "/var/log/php8.3-fpm.log",
	"postfix":      "/var/log/mail.log",
	"dovecot":      "/var/log/dovecot.log",
	"rspamd":       "/var/log/rspamd/rspamd.log",
	"mysql":        "/var/log/mysql/error.log",
	"syslog":       "/var/log/syslog",
	"auth":         "/var/log/auth.log",
	"fail2ban":     "/var/log/fail2ban.log",
}

const maxLines = 500

// ReadLog returns the last n lines (capped at maxLines) of an allowed log.
func ReadLog(service string, lines int) ([]string, error) {
	if lines <= 0 || lines > maxLines {
		lines = 100
	}

	logPath, ok := allowedLogs[service]
	if !ok {
		return nil, fmt.Errorf("unknown log: %s", service)
	}

	// Extra safety: resolve symlinks and confirm the path is still under /var/log
	resolved, err := filepath.EvalSymlinks(logPath)
	if err != nil {
		if os.IsNotExist(err) {
			return []string{}, nil
		}
		return nil, err
	}
	if !strings.HasPrefix(resolved, "/var/log/") {
		return nil, fmt.Errorf("log path outside allowed directory")
	}

	f, err := os.Open(resolved)
	if err != nil {
		if os.IsNotExist(err) {
			return []string{}, nil
		}
		return nil, err
	}
	defer f.Close()

	return tailLines(f, lines), nil
}

// ListLogs returns the set of log names the agent can serve.
func ListLogs() []string {
	names := make([]string, 0, len(allowedLogs))
	for k := range allowedLogs {
		names = append(names, k)
	}
	return names
}

// tailLines reads the last n lines from a reader without loading the whole file.
func tailLines(r io.ReadSeeker, n int) []string {
	ring := make([]string, n)
	pos := 0
	count := 0

	scanner := bufio.NewScanner(r)
	scanner.Buffer(make([]byte, 1024*1024), 1024*1024)
	for scanner.Scan() {
		ring[pos%n] = scanner.Text()
		pos++
		count++
	}

	if count <= n {
		out := make([]string, count)
		copy(out, ring[:count])
		return out
	}

	out := make([]string, n)
	start := pos % n
	for i := 0; i < n; i++ {
		out[i] = ring[(start+i)%n]
	}
	return out
}
