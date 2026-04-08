package api

import (
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
)

var safeHostnamePattern = regexp.MustCompile(`^[A-Za-z0-9.-]+$`)

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
	var req struct {
		Hostname string `json:"hostname"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "invalid JSON: "+err.Error(), http.StatusBadRequest)
		return
	}

	hostname := strings.TrimSpace(req.Hostname)
	if hostname == "" {
		out, err := exec.Command("hostname", "-f").Output()
		if err != nil {
			http.Error(w, "hostname required and hostname -f failed: "+err.Error(), http.StatusBadRequest)
			return
		}
		hostname = strings.TrimSpace(string(out))
	}
	if hostname == "" || !safeHostnamePattern.MatchString(hostname) || strings.Contains(hostname, "..") {
		http.Error(w, "invalid hostname", http.StatusBadRequest)
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
	script := strings.Join([]string{
		"set -e",
		"export PATH=/root/.acme.sh:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH",
		"mkdir -p " + shellQuote(filepath.Join(webroot, ".well-known/acme-challenge")),
		"if [ ! -x /root/.acme.sh/acme.sh ]; then curl -fsSL https://get.acme.sh | sh -s email=admin@" + shellQuote(parentDomain(hostname)) + "; fi",
		"/root/.acme.sh/acme.sh --set-default-ca --server letsencrypt >/dev/null 2>&1 || true",
		"/root/.acme.sh/acme.sh --issue -w " + shellQuote(webroot) + " -d " + shellQuote(hostname) + " --keylength 4096 --force",
		"/root/.acme.sh/acme.sh --install-cert -d " + shellQuote(hostname) + " --key-file " + shellQuote(keyPath) + " --fullchain-file " + shellQuote(certPath) + " --reloadcmd " + shellQuote("systemctl restart strata-agent strata-webdav postfix dovecot nginx php8.4-fpm 2>/dev/null || true"),
	}, "\n")

	cmd := exec.Command("sh", "-c", script+" >>"+shellQuote(logPath)+" 2>&1")
	if err := cmd.Start(); err != nil {
		http.Error(w, "start renewal: "+err.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusAccepted, map[string]string{
		"status":   "renewal_started",
		"hostname": hostname,
		"log":      logPath,
	})
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
