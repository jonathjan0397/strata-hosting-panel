package api

import (
	"fmt"
	"net/http"
	"os/exec"
	"regexp"
	"strconv"
	"strings"

	"github.com/go-chi/chi/v5"
)

type firewallRule struct {
	Number int    `json:"number"`
	To     string `json:"to"`
	Action string `json:"action"`
	From   string `json:"from"`
}

// ensureUFW installs and enables UFW if not present. Returns an error string on failure.
func ensureUFW() error {
	if _, err := exec.LookPath("ufw"); err == nil {
		return nil
	}
	// Not found — try to install
	out, err := exec.Command("apt-get", "install", "-y", "ufw").CombinedOutput()
	if err != nil {
		return fmt.Errorf("ufw not installed and auto-install failed: %s", strings.TrimSpace(string(out)))
	}
	// Enable with default-deny incoming, allow outgoing
	exec.Command("ufw", "default", "deny", "incoming").Run()
	exec.Command("ufw", "default", "allow", "outgoing").Run()
	// Always allow SSH before enabling so we don't lock ourselves out
	exec.Command("ufw", "allow", "22/tcp").Run()
	exec.Command("ufw", "allow", "80/tcp").Run()
	exec.Command("ufw", "allow", "443/tcp").Run()
	exec.Command("ufw", "allow", "8743/tcp").Run()
	exec.Command("ufw", "--force", "enable").Run()
	return nil
}

// GET /v1/firewall/rules
func handleFirewallRules(w http.ResponseWriter, r *http.Request) {
	if err := ensureUFW(); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	out, err := exec.Command("ufw", "status", "numbered").Output()
	if err != nil {
		http.Error(w, "ufw error: "+err.Error(), http.StatusInternalServerError)
		return
	}

	body := string(out)
	status := "inactive"
	if strings.Contains(body, "Status: active") {
		status = "active"
	}

	respond(w, http.StatusOK, map[string]interface{}{
		"status": status,
		"rules":  parseUFWRules(body),
	})
}

// POST /v1/firewall/rules
func handleFirewallAddRule(w http.ResponseWriter, r *http.Request) {
	if err := ensureUFW(); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	var req struct {
		Type  string `json:"type"`  // allow | deny
		Port  string `json:"port"`  // e.g. "80", "8080:9090"
		Proto string `json:"proto"` // tcp | udp | ""
		From  string `json:"from"`  // "" = anywhere, or IP/CIDR
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	if req.Type != "allow" && req.Type != "deny" {
		http.Error(w, "type must be allow or deny", http.StatusBadRequest)
		return
	}

	portRe := regexp.MustCompile(`^\d{1,5}(:\d{1,5})?$`)
	if !portRe.MatchString(req.Port) {
		http.Error(w, "invalid port", http.StatusBadRequest)
		return
	}

	if req.Proto != "" && req.Proto != "tcp" && req.Proto != "udp" {
		http.Error(w, "proto must be tcp, udp, or empty", http.StatusBadRequest)
		return
	}

	var args []string
	if req.From != "" {
		fromRe := regexp.MustCompile(`^[\d.:a-fA-F/]+$`)
		if !fromRe.MatchString(req.From) {
			http.Error(w, "invalid from address", http.StatusBadRequest)
			return
		}
		args = []string{req.Type, "from", req.From, "to", "any", "port", req.Port}
		if req.Proto != "" {
			args = append(args, "proto", req.Proto)
		}
	} else {
		rule := req.Port
		if req.Proto != "" {
			rule = req.Port + "/" + req.Proto
		}
		args = []string{req.Type, rule}
	}

	out, err := exec.Command("ufw", args...).CombinedOutput()
	if err != nil {
		http.Error(w, "ufw error: "+string(out), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusCreated, map[string]string{
		"status": "added",
		"output": strings.TrimSpace(string(out)),
	})
}

// DELETE /v1/firewall/rules/{number}
func handleFirewallDeleteRule(w http.ResponseWriter, r *http.Request) {
	if err := ensureUFW(); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	numStr := chi.URLParam(r, "number")
	n, err := strconv.Atoi(numStr)
	if err != nil || n < 1 {
		http.Error(w, "invalid rule number", http.StatusBadRequest)
		return
	}

	out, err := exec.Command("ufw", "--force", "delete", numStr).CombinedOutput()
	if err != nil {
		http.Error(w, "ufw delete error: "+string(out), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "deleted"})
}

var ufwRuleRe = regexp.MustCompile(`\[\s*(\d+)\]\s+(.+?)\s{2,}(ALLOW IN|DENY IN|REJECT IN|ALLOW OUT|DENY OUT|LIMIT IN|ALLOW|DENY|REJECT)\s+(.+)`)

func parseUFWRules(output string) []firewallRule {
	var rules []firewallRule
	for _, line := range strings.Split(output, "\n") {
		m := ufwRuleRe.FindStringSubmatch(line)
		if m == nil {
			continue
		}
		num, _ := strconv.Atoi(strings.TrimSpace(m[1]))
		// Skip IPv6 duplicate entries (contain "(v6)")
		if strings.Contains(m[2], "(v6)") || strings.Contains(m[4], "(v6)") {
			continue
		}
		rules = append(rules, firewallRule{
			Number: num,
			To:     strings.TrimSpace(m[2]),
			Action: strings.TrimSpace(m[3]),
			From:   strings.TrimSpace(m[4]),
		})
	}
	return rules
}
