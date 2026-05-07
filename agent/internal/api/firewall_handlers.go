package api

import (
	"net"
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

// requireUFW returns an error when ufw is unavailable on the node.
func requireUFW() error {
	_, err := exec.LookPath("ufw")
	// Not found — try to install
	return err
}

// GET /v1/firewall/rules
func handleFirewallRules(w http.ResponseWriter, r *http.Request) {
	if err := requireUFW(); err != nil {
		http.Error(w, "ufw is not installed", http.StatusServiceUnavailable)
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
	if err := requireUFW(); err != nil {
		http.Error(w, "ufw is not installed", http.StatusServiceUnavailable)
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

	if req.Proto != "" && req.Proto != "tcp" && req.Proto != "udp" {
		http.Error(w, "proto must be tcp, udp, or empty", http.StatusBadRequest)
		return
	}

	var args []string
	if req.From != "" {
		if !validFirewallAddress(req.From) {
			http.Error(w, "invalid from address", http.StatusBadRequest)
			return
		}

		if req.Port == "" {
			args = []string{req.Type, "from", req.From}
		} else {
			if !validFirewallPort(req.Port) {
				http.Error(w, "invalid port", http.StatusBadRequest)
				return
			}
			args = []string{req.Type, "from", req.From, "to", "any", "port", req.Port}
			if req.Proto != "" {
				args = append(args, "proto", req.Proto)
			}
		}
	} else {
		if req.Port == "" || !validFirewallPort(req.Port) {
			http.Error(w, "invalid port", http.StatusBadRequest)
			return
		}
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
	if err := requireUFW(); err != nil {
		http.Error(w, "ufw is not installed", http.StatusServiceUnavailable)
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

func validFirewallAddress(value string) bool {
	if net.ParseIP(value) != nil {
		return true
	}

	_, _, err := net.ParseCIDR(value)
	return err == nil
}

func validFirewallPort(value string) bool {
	return regexp.MustCompile(`^\d{1,5}(:\d{1,5})?$`).MatchString(value)
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
