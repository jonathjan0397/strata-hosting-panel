package api

import (
	"bufio"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

const fail2banStrataConfigPath = "/etc/fail2ban/jail.d/strata-defaults.local"

type fail2banJail struct {
	Name            string   `json:"name"`
	CurrentlyFailed int      `json:"currently_failed"`
	TotalBanned     int      `json:"total_banned"`
	BannedIPs       []string `json:"banned_ips"`
}

type fail2banJailConfig struct {
	Name      string `json:"name"`
	Label     string `json:"label"`
	Enabled   bool   `json:"enabled"`
	Available bool   `json:"available"`
	MaxRetry  *int   `json:"maxretry,omitempty"`
	FindTime  *int   `json:"findtime,omitempty"`
	BanTime   *int   `json:"bantime,omitempty"`
}

type fail2banConfigPayload struct {
	Defaults map[string]int   `json:"defaults"`
	Jails    []fail2banJailConfig `json:"jails"`
}

type fail2banJailMeta struct {
	Label       string
	EnabledByDefault bool
	Available    func() bool
}

var fail2banManagedJails = []struct {
	Name string
	Meta fail2banJailMeta
}{
	{"sshd", fail2banJailMeta{Label: "SSH", EnabledByDefault: true, Available: func() bool { return true }}},
	{"postfix", fail2banJailMeta{Label: "Postfix", EnabledByDefault: true, Available: func() bool { return commandExists("postfix") }}},
	{"postfix-sasl", fail2banJailMeta{Label: "Postfix SASL", EnabledByDefault: true, Available: func() bool { return commandExists("postfix") }}},
	{"dovecot", fail2banJailMeta{Label: "Dovecot", EnabledByDefault: true, Available: func() bool { return commandExists("dovecot") }}},
	{"pure-ftpd", fail2banJailMeta{Label: "Pure-FTPd", EnabledByDefault: true, Available: func() bool { return commandExists("pure-ftpd") || fileExists("/usr/sbin/pure-ftpd") }}},
	{"nginx-http-auth", fail2banJailMeta{Label: "Nginx HTTP Auth", EnabledByDefault: true, Available: func() bool { return commandExists("nginx") }}},
	{"apache-auth", fail2banJailMeta{Label: "Apache Auth", EnabledByDefault: true, Available: func() bool { return commandExists("apache2") || fileExists("/usr/sbin/apache2") }}},
	{"recidive", fail2banJailMeta{Label: "Repeat Offenders", EnabledByDefault: true, Available: func() bool { return true }}},
}

func requireFail2ban() error {
	_, err := exec.LookPath("fail2ban-client")
	return err
}

// GET /fail2ban/status returns all jails and their banned IPs.
func handleFail2BanStatus(w http.ResponseWriter, r *http.Request) {
	if err := requireFail2ban(); err != nil {
		http.Error(w, "fail2ban is not installed", http.StatusServiceUnavailable)
		return
	}

	out, err := exec.Command("fail2ban-client", "status").Output()
	if err != nil {
		http.Error(w, "fail2ban unavailable: "+err.Error(), http.StatusServiceUnavailable)
		return
	}

	names := parseFail2BanJailList(string(out))
	jails := make([]fail2banJail, 0, len(names))
	for _, name := range names {
		jails = append(jails, getFail2BanJailStatus(name))
	}

	respond(w, http.StatusOK, map[string]any{"jails": jails})
}

func handleFail2BanConfig(w http.ResponseWriter, r *http.Request) {
	if err := requireFail2ban(); err != nil {
		http.Error(w, "fail2ban is not installed", http.StatusServiceUnavailable)
		return
	}

	payload, err := loadFail2banConfig()
	if err != nil {
		http.Error(w, "failed to read fail2ban config: "+err.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, payload)
}

func handleFail2BanConfigUpdate(w http.ResponseWriter, r *http.Request) {
	if err := requireFail2ban(); err != nil {
		http.Error(w, "fail2ban is not installed", http.StatusServiceUnavailable)
		return
	}

	var req fail2banConfigPayload
	if !decodeJSON(w, r, &req) {
		return
	}

	content, err := renderFail2banConfig(req)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	previous, _ := os.ReadFile(fail2banStrataConfigPath)
	if err := os.MkdirAll(filepath.Dir(fail2banStrataConfigPath), 0755); err != nil {
		http.Error(w, "failed to prepare fail2ban config directory: "+err.Error(), http.StatusInternalServerError)
		return
	}
	if err := os.WriteFile(fail2banStrataConfigPath, []byte(content), 0644); err != nil {
		http.Error(w, "failed to write fail2ban config: "+err.Error(), http.StatusInternalServerError)
		return
	}

	out, err := exec.Command("systemctl", "restart", "fail2ban").CombinedOutput()
	if err != nil {
		if len(previous) > 0 {
			_ = os.WriteFile(fail2banStrataConfigPath, previous, 0644)
			_, _ = exec.Command("systemctl", "restart", "fail2ban").CombinedOutput()
		}
		http.Error(w, "fail2ban restart failed: "+strings.TrimSpace(string(out)), http.StatusBadRequest)
		return
	}

	payload, err := loadFail2banConfig()
	if err != nil {
		http.Error(w, "config applied but could not be re-read: "+err.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, payload)
}

// POST /fail2ban/unban body: {"jail":"sshd","ip":"1.2.3.4"}
func handleFail2BanUnban(w http.ResponseWriter, r *http.Request) {
	if err := requireFail2ban(); err != nil {
		http.Error(w, "fail2ban is not installed", http.StatusServiceUnavailable)
		return
	}

	var req struct {
		Jail string `json:"jail"`
		IP   string `json:"ip"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	jailRe := regexp.MustCompile(`^[a-zA-Z0-9_-]+$`)
	ipRe := regexp.MustCompile(`^[\d.:a-fA-F/]+$`)
	if !jailRe.MatchString(req.Jail) || !ipRe.MatchString(req.IP) {
		http.Error(w, "invalid jail or ip", http.StatusBadRequest)
		return
	}

	out, err := exec.Command("fail2ban-client", "set", req.Jail, "unbanip", req.IP).CombinedOutput()
	if err != nil {
		http.Error(w, "unban failed: "+strings.TrimSpace(string(out)), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "unbanned", "ip": req.IP, "jail": req.Jail})
}

func handleFail2BanBan(w http.ResponseWriter, r *http.Request) {
	if err := requireFail2ban(); err != nil {
		http.Error(w, "fail2ban is not installed", http.StatusServiceUnavailable)
		return
	}

	var req struct {
		Jail string `json:"jail"`
		IP   string `json:"ip"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	jailRe := regexp.MustCompile(`^[a-zA-Z0-9_-]+$`)
	ipRe := regexp.MustCompile(`^[\d.:a-fA-F/]+$`)
	if !jailRe.MatchString(req.Jail) || !ipRe.MatchString(req.IP) {
		http.Error(w, "invalid jail or ip", http.StatusBadRequest)
		return
	}

	out, err := exec.Command("fail2ban-client", "set", req.Jail, "banip", req.IP).CombinedOutput()
	if err != nil {
		http.Error(w, "ban failed: "+strings.TrimSpace(string(out)), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "banned", "ip": req.IP, "jail": req.Jail})
}

func parseFail2BanJailList(output string) []string {
	var names []string
	scanner := bufio.NewScanner(strings.NewReader(output))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if strings.Contains(line, "Jail list:") {
			raw := line[strings.Index(line, "Jail list:")+len("Jail list:"):]
			for _, name := range strings.Split(raw, ",") {
				if n := strings.TrimSpace(name); n != "" {
					names = append(names, n)
				}
			}
		}
	}
	return names
}

func getFail2BanJailStatus(jail string) fail2banJail {
	result := fail2banJail{Name: jail, BannedIPs: []string{}}
	out, err := exec.Command("fail2ban-client", "status", jail).Output()
	if err != nil {
		return result
	}
	scanner := bufio.NewScanner(strings.NewReader(string(out)))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if strings.Contains(line, "Currently failed:") {
			if value, err := strconv.Atoi(strings.TrimSpace(strings.TrimPrefix(line, "Currently failed:"))); err == nil {
				result.CurrentlyFailed = value
			}
		}
		if strings.Contains(line, "Banned IP list:") {
			idx := strings.Index(line, ":") + 1
			for _, ip := range strings.Fields(line[idx:]) {
				result.BannedIPs = append(result.BannedIPs, ip)
			}
		}
	}
	result.TotalBanned = len(result.BannedIPs)
	return result
}

func loadFail2banConfig() (fail2banConfigPayload, error) {
	content, err := os.ReadFile(fail2banStrataConfigPath)
	if err != nil && !os.IsNotExist(err) {
		return fail2banConfigPayload{}, err
	}

	parsed := parseFail2banConfig(string(content))
	defaults := map[string]int{
		"bantime": 3600,
		"findtime": 600,
		"maxretry": 10,
	}
	for key, value := range parsed["DEFAULT"] {
		if n, err := strconv.Atoi(value); err == nil {
			defaults[strings.ToLower(key)] = n
		}
	}

	jails := make([]fail2banJailConfig, 0, len(fail2banManagedJails))
	for _, managed := range fail2banManagedJails {
		section := parsed[managed.Name]
		enabled := managed.Meta.EnabledByDefault
		if value, ok := section["enabled"]; ok {
			enabled = strings.EqualFold(value, "true")
		}
		jail := fail2banJailConfig{
			Name: managed.Name,
			Label: managed.Meta.Label,
			Enabled: enabled,
			Available: managed.Meta.Available(),
		}
		if value, ok := section["maxretry"]; ok {
			if n, err := strconv.Atoi(value); err == nil {
				jail.MaxRetry = &n
			}
		}
		if value, ok := section["findtime"]; ok {
			if n, err := strconv.Atoi(value); err == nil {
				jail.FindTime = &n
			}
		}
		if value, ok := section["bantime"]; ok {
			if n, err := strconv.Atoi(value); err == nil {
				jail.BanTime = &n
			}
		}
		jails = append(jails, jail)
	}

	return fail2banConfigPayload{
		Defaults: defaults,
		Jails: jails,
	}, nil
}

func parseFail2banConfig(content string) map[string]map[string]string {
	result := map[string]map[string]string{}
	current := ""
	scanner := bufio.NewScanner(strings.NewReader(content))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
			current = strings.TrimSpace(strings.TrimSuffix(strings.TrimPrefix(line, "["), "]"))
			if _, ok := result[current]; !ok {
				result[current] = map[string]string{}
			}
			continue
		}
		if current == "" || !strings.Contains(line, "=") {
			continue
		}
		parts := strings.SplitN(line, "=", 2)
		key := strings.ToLower(strings.TrimSpace(parts[0]))
		value := strings.TrimSpace(parts[1])
		result[current][key] = value
	}
	return result
}

func renderFail2banConfig(payload fail2banConfigPayload) (string, error) {
	defaults := map[string]int{
		"bantime": 3600,
		"findtime": 600,
		"maxretry": 10,
	}
	for key, value := range payload.Defaults {
		key = strings.ToLower(strings.TrimSpace(key))
		if key != "bantime" && key != "findtime" && key != "maxretry" {
			continue
		}
		if value <= 0 {
			return "", fmt.Errorf("%s must be greater than zero", key)
		}
		defaults[key] = value
	}

	metaByName := map[string]fail2banJailMeta{}
	for _, managed := range fail2banManagedJails {
		metaByName[managed.Name] = managed.Meta
	}

	var b strings.Builder
	b.WriteString("[DEFAULT]\n")
	b.WriteString(fmt.Sprintf("bantime = %d\n", defaults["bantime"]))
	b.WriteString(fmt.Sprintf("findtime = %d\n", defaults["findtime"]))
	b.WriteString(fmt.Sprintf("maxretry = %d\n", defaults["maxretry"]))
	b.WriteString("backend = systemd\n\n")

	seen := map[string]bool{}
	for _, jail := range payload.Jails {
		meta, ok := metaByName[jail.Name]
		if !ok {
			continue
		}
		seen[jail.Name] = true
		if jail.MaxRetry != nil && *jail.MaxRetry <= 0 {
			return "", fmt.Errorf("%s maxretry must be greater than zero", jail.Name)
		}
		if jail.FindTime != nil && *jail.FindTime <= 0 {
			return "", fmt.Errorf("%s findtime must be greater than zero", jail.Name)
		}
		if jail.BanTime != nil && *jail.BanTime <= 0 {
			return "", fmt.Errorf("%s bantime must be greater than zero", jail.Name)
		}

		b.WriteString("[" + jail.Name + "]\n")
		b.WriteString(fmt.Sprintf("enabled = %s\n", boolString(jail.Enabled && meta.Available())))
		if jail.MaxRetry != nil {
			b.WriteString(fmt.Sprintf("maxretry = %d\n", *jail.MaxRetry))
		}
		if jail.FindTime != nil {
			b.WriteString(fmt.Sprintf("findtime = %d\n", *jail.FindTime))
		}
		if jail.BanTime != nil {
			b.WriteString(fmt.Sprintf("bantime = %d\n", *jail.BanTime))
		}
		b.WriteString("\n")
	}

	for _, managed := range fail2banManagedJails {
		if seen[managed.Name] {
			continue
		}
		b.WriteString("[" + managed.Name + "]\n")
		b.WriteString(fmt.Sprintf("enabled = %s\n\n", boolString(managed.Meta.EnabledByDefault && managed.Meta.Available())))
	}

	return b.String(), nil
}

func commandExists(name string) bool {
	_, err := exec.LookPath(name)
	return err == nil
}

func fileExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}

func boolString(value bool) string {
	if value {
		return "true"
	}
	return "false"
}
