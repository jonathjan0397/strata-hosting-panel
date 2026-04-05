package api

import (
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"

	"github.com/go-chi/chi/v5"
)

const vMailBase = "/var/mail/vmail"

// POST /v1/mail/autoresponder
func handleAutoresponderSet(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Email   string `json:"email"`
		Subject string `json:"subject"`
		Body    string `json:"body"`
		Active  bool   `json:"active"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	parts := strings.SplitN(req.Email, "@", 2)
	if len(parts) != 2 {
		http.Error(w, "invalid email", http.StatusBadRequest)
		return
	}
	local, domain := parts[0], parts[1]
	if err := writeAutoresponder(domain, local, req.Subject, req.Body, req.Active); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "ok", "email": req.Email})
}

// DELETE /v1/mail/autoresponder/{email}
func handleAutoresponderDelete(w http.ResponseWriter, r *http.Request) {
	email := chi.URLParam(r, "email")
	parts := strings.SplitN(email, "@", 2)
	if len(parts) != 2 {
		http.Error(w, "invalid email", http.StatusBadRequest)
		return
	}
	local, domain := parts[0], parts[1]
	sieveFile := filepath.Join(vMailBase, domain, local, ".dovecot.sieve")
	_ = os.Remove(sieveFile)
	_ = os.Remove(sieveFile + "c")
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "email": email})
}

func writeAutoresponder(domain, local, subject, body string, active bool) error {
	dir := filepath.Join(vMailBase, domain, local)
	if err := os.MkdirAll(dir, 0750); err != nil {
		return err
	}
	sieveFile := filepath.Join(dir, ".dovecot.sieve")
	if !active {
		_ = os.Remove(sieveFile)
		_ = os.Remove(sieveFile + "c")
		return nil
	}
	subject = strings.ReplaceAll(subject, `"`, `\"`)
	bodyEsc := strings.ReplaceAll(body, `\`, `\\`)
	bodyEsc = strings.ReplaceAll(bodyEsc, `"`, `\"`)
	content := fmt.Sprintf("require [\"vacation\"];\nvacation\n  :days 1\n  :subject \"%s\"\n  \"%s\";\n", subject, bodyEsc)
	if err := os.WriteFile(sieveFile, []byte(content), 0640); err != nil {
		return err
	}
	_ = os.Chown(sieveFile, 5000, 5000)
	return nil
}
