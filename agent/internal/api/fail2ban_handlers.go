package api

import (
	"bufio"
	"fmt"
	"net/http"
	"os/exec"
	"regexp"
	"strings"
)

type fail2banJail struct {
	Name        string   `json:"name"`
	TotalBanned int      `json:"total_banned"`
	BannedIPs   []string `json:"banned_ips"`
}

func ensureFail2ban() error {
	if _, err := exec.LookPath("fail2ban-client"); err == nil {
		return nil
	}
	out, err := exec.Command("apt-get", "install", "-y", "fail2ban").CombinedOutput()
	if err != nil {
		return fmt.Errorf("fail2ban not installed and auto-install failed: %s", strings.TrimSpace(string(out)))
	}
	exec.Command("systemctl", "enable", "--now", "fail2ban").Run()
	return nil
}

// GET /fail2ban/status — returns all jails and their banned IPs.
func handleFail2BanStatus(w http.ResponseWriter, r *http.Request) {
	if err := ensureFail2ban(); err != nil {
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
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

// POST /fail2ban/unban — body: {"jail":"sshd","ip":"1.2.3.4"}
func handleFail2BanUnban(w http.ResponseWriter, r *http.Request) {
	if err := ensureFail2ban(); err != nil {
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
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

	out, err := exec.Command("fail2ban-client", "unbanip", req.Jail, req.IP).CombinedOutput()
	if err != nil {
		http.Error(w, "unban failed: "+strings.TrimSpace(string(out)), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "unbanned", "ip": req.IP, "jail": req.Jail})
}

func parseFail2BanJailList(output string) []string {
	var names []string
	scanner := bufio.NewScanner(strings.NewReader(output))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if strings.HasPrefix(line, "Jail list:") {
			raw := strings.TrimPrefix(line, "Jail list:")
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
