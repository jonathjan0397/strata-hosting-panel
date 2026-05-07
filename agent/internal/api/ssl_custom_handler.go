package api

import (
	"crypto/x509"
	"encoding/pem"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
)

// POST /v1/ssl/store/{domain} — store a custom certificate and key for a domain.
// The panel re-provisions the vhost separately using createDomain with ssl_enabled=true.
func handleSSLStore(w http.ResponseWriter, r *http.Request) {
	domain := chi.URLParam(r, "domain")
	if !validDomain(domain) {
		http.Error(w, "invalid domain", http.StatusBadRequest)
		return
	}

	var req struct {
		CertPEM string `json:"cert_pem"`
		KeyPEM  string `json:"key_pem"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	if !strings.Contains(req.CertPEM, "-----BEGIN CERTIFICATE-----") {
		http.Error(w, "invalid certificate PEM", http.StatusBadRequest)
		return
	}
	if !strings.Contains(req.KeyPEM, "PRIVATE KEY-----") {
		http.Error(w, "invalid private key PEM", http.StatusBadRequest)
		return
	}

	dir := "/etc/strata-agent/certs/" + domain
	if err := os.MkdirAll(dir, 0700); err != nil {
		http.Error(w, "failed to create cert dir: "+err.Error(), http.StatusInternalServerError)
		return
	}

	certPath := dir + "/cert.pem"
	keyPath := dir + "/key.pem"

	if err := os.WriteFile(certPath, []byte(req.CertPEM), 0644); err != nil {
		http.Error(w, "failed to write cert: "+err.Error(), http.StatusInternalServerError)
		return
	}
	if err := os.WriteFile(keyPath, []byte(req.KeyPEM), 0600); err != nil {
		http.Error(w, "failed to write key: "+err.Error(), http.StatusInternalServerError)
		return
	}

	// Parse cert expiry
	expires := ""
	if block, _ := pem.Decode([]byte(req.CertPEM)); block != nil {
		if cert, err := x509.ParseCertificate(block.Bytes); err == nil {
			expires = cert.NotAfter.Format(time.RFC3339)
		}
	}

	respond(w, http.StatusOK, map[string]string{
		"cert_file": certPath,
		"key_file":  keyPath,
		"expires":   expires,
	})
}

// validDomain returns true if the domain string looks safe (reuse pattern from elsewhere).
func validDomain(domain string) bool {
	if len(domain) == 0 || len(domain) > 253 {
		return false
	}
	for _, ch := range domain {
		if !((ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') ||
			(ch >= '0' && ch <= '9') || ch == '.' || ch == '-') {
			return false
		}
	}
	return true
}
