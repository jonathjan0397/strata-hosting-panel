package api

import (
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"encoding/pem"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
)

var safeHostnamePattern = regexp.MustCompile(`^[A-Za-z0-9.-]+$`)

type certificateRequest struct {
	Hostname string `json:"hostname"`
	Profile  string `json:"profile"`
}

func handleAgentCertificateInfo(w http.ResponseWriter, r *http.Request) {
	certPath := os.Getenv("STRATA_TLS_CERT")
	if certPath == "" {
		certPath = "/etc/strata-agent/tls/cert.pem"
	}

	pemBytes, err := os.ReadFile(certPath)
	if err != nil {
		http.Error(w, "read certificate: "+err.Error(), http.StatusInternalServerError)
		return
	}

	block, _ := pem.Decode(pemBytes)
	if block == nil {
		http.Error(w, "invalid certificate PEM", http.StatusInternalServerError)
		return
	}

	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		http.Error(w, "parse certificate: "+err.Error(), http.StatusInternalServerError)
		return
	}

	sum := sha256.Sum256(cert.Raw)
	respond(w, http.StatusOK, map[string]any{
		"subject":        cert.Subject.String(),
		"issuer":         cert.Issuer.String(),
		"not_before":     cert.NotBefore,
		"not_after":      cert.NotAfter,
		"dns_names":      cert.DNSNames,
		"fingerprint":    strings.ToUpper(hex.EncodeToString(sum[:])),
		"is_self_signed": cert.Issuer.String() == cert.Subject.String(),
	})
}

func handleAgentCertificateRenew(w http.ResponseWriter, r *http.Request) {
	var req certificateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid JSON: "+err.Error(), http.StatusBadRequest)
		return
	}

	hostname, err := normalizeCertificateHostname(req.Hostname)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	certPath := os.Getenv("STRATA_TLS_CERT")
	if certPath == "" {
		certPath = "/etc/strata-agent/tls/cert.pem"
	}
	keyPath := os.Getenv("STRATA_TLS_KEY")
	if keyPath == "" {
		keyPath = "/etc/strata-agent/tls/key.pem"
	}

	webroot := "/var/www/html"
	if _, err := os.Stat("/opt/strata-panel/panel/public"); err == nil {
		webroot = "/opt/strata-panel/panel/public"
	}

	logPath := "/var/log/strata-agent-cert-renew.log"
	if err := startCertificateRepair(hostname, certPath, keyPath, webroot, "systemctl restart strata-agent strata-webdav postfix dovecot nginx apache2 php8.4-fpm 2>/dev/null || true", logPath); err != nil {
		http.Error(w, "start renewal: "+err.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusAccepted, map[string]string{
		"status":   "renewal_started",
		"hostname": hostname,
		"log":      logPath,
	})
}

func handleManagedCertificateRepair(w http.ResponseWriter, r *http.Request) {
	var req certificateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid JSON: "+err.Error(), http.StatusBadRequest)
		return
	}

	hostname, err := normalizeCertificateHostname(req.Hostname)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	profile := strings.TrimSpace(req.Profile)
	if profile == "" {
		profile = "panel"
	}

	var certPath string
	var keyPath string
	var webroot string
	var reloadCmd string
	var logPath string

	switch profile {
	case "panel":
		certPath = "/etc/strata-panel/tls/fullchain.pem"
		keyPath = "/etc/strata-panel/tls/privkey.pem"
		webroot = "/opt/strata-panel/panel/public"
		if _, err := os.Stat(webroot); err != nil {
			webroot = "/var/www/html"
		}
		reloadCmd = "systemctl restart strata-agent strata-webdav postfix dovecot nginx apache2 php8.4-fpm 2>/dev/null || true"
		logPath = "/var/log/strata-panel-cert-repair.log"
	case "apex_placeholder":
		certPath = "/etc/strata-panel/apex-tls/fullchain.pem"
		keyPath = "/etc/strata-panel/apex-tls/privkey.pem"
		webroot = "/var/www/strata-placeholder"
		reloadCmd = "systemctl reload nginx apache2 2>/dev/null || true"
		logPath = "/var/log/strata-apex-cert-repair.log"
	default:
		http.Error(w, "invalid certificate profile", http.StatusBadRequest)
		return
	}

	if err := startCertificateRepair(hostname, certPath, keyPath, webroot, reloadCmd, logPath); err != nil {
		http.Error(w, "start repair: "+err.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusAccepted, map[string]string{
		"status":   "repair_started",
		"hostname": hostname,
		"profile":  profile,
		"log":      logPath,
	})
}

func normalizeCertificateHostname(input string) (string, error) {
	hostname := strings.TrimSpace(input)
	if hostname == "" {
		out, err := exec.Command("hostname", "-f").Output()
		if err != nil {
			return "", fmt.Errorf("hostname required and hostname -f failed: %w", err)
		}
		hostname = strings.TrimSpace(string(out))
	}
	if hostname == "" || !safeHostnamePattern.MatchString(hostname) || strings.Contains(hostname, "..") {
		return "", fmt.Errorf("invalid hostname")
	}

	return hostname, nil
}

func startCertificateRepair(hostname, certPath, keyPath, webroot, reloadCmd, logPath string) error {
	script := strings.Join([]string{
		"set -e",
		"export PATH=/root/.acme.sh:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH",
		"mkdir -p " + shellQuote(filepath.Dir(certPath)) + " " + shellQuote(filepath.Dir(keyPath)),
		"mkdir -p " + shellQuote(filepath.Join(webroot, ".well-known/acme-challenge")),
		"if [ ! -x /root/.acme.sh/acme.sh ]; then curl -fsSL https://get.acme.sh | sh -s email=admin@" + shellQuote(parentDomain(hostname)) + "; fi",
		"/root/.acme.sh/acme.sh --set-default-ca --server letsencrypt >/dev/null 2>&1 || true",
		"/root/.acme.sh/acme.sh --issue -w " + shellQuote(webroot) + " -d " + shellQuote(hostname) + " --keylength 4096 --force",
		"/root/.acme.sh/acme.sh --install-cert -d " + shellQuote(hostname) + " --key-file " + shellQuote(keyPath) + " --fullchain-file " + shellQuote(certPath) + " --reloadcmd " + shellQuote(reloadCmd),
	}, "\n")

	cmd := exec.Command("sh", "-c", script+" >>"+shellQuote(logPath)+" 2>&1")
	return cmd.Start()
}

func parentDomain(hostname string) string {
	parts := strings.Split(hostname, ".")
	if len(parts) >= 2 {
		return strings.Join(parts[len(parts)-2:], ".")
	}
	return hostname
}

func shellQuote(value string) string {
	return "'" + strings.ReplaceAll(value, "'", "'\"'\"'") + "'"
}
