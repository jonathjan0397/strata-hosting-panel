package api

import (
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
	script := ""
	if req.Active {
		subject := escapeSieveString(req.Subject)
		body := escapeSieveMultiline(req.Body)
		script = "require [\"vacation\"];\n\nvacation\n  :days 1\n  :subject \"" + subject + "\"\n  text:\n" + body + "\n.\n;\n"
	}
	if err := writeMailboxSieve(domain, local, script); err != nil {
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
	_ = writeMailboxSieve(domain, local, "")
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "email": email})
}

func handleMailboxSieveSet(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Email  string `json:"email"`
		Script string `json:"script"`
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
	if err := writeMailboxSieve(domain, local, req.Script); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "ok", "email": req.Email})
}

func handleMailboxSieveDelete(w http.ResponseWriter, r *http.Request) {
	email := chi.URLParam(r, "email")
	parts := strings.SplitN(email, "@", 2)
	if len(parts) != 2 {
		http.Error(w, "invalid email", http.StatusBadRequest)
		return
	}
	local, domain := parts[0], parts[1]
	if err := writeMailboxSieve(domain, local, ""); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "email": email})
}

func writeMailboxSieve(domain, local, script string) error {
	dir := filepath.Join(vMailBase, domain, local)
	if err := os.MkdirAll(dir, 0750); err != nil {
		return err
	}
	sieveFile := filepath.Join(dir, ".dovecot.sieve")
	if strings.TrimSpace(script) == "" {
		_ = os.Remove(sieveFile)
		_ = os.Remove(sieveFile + "c")
		return nil
	}
	if err := os.WriteFile(sieveFile, []byte(script), 0640); err != nil {
		return err
	}
	_ = os.Chown(sieveFile, 5000, 5000)
	return nil
}

func escapeSieveString(value string) string {
	value = strings.ReplaceAll(value, `\`, `\\`)
	return strings.ReplaceAll(value, `"`, `\"`)
}

func escapeSieveMultiline(value string) string {
	return strings.ReplaceAll(value, "\r", "")
}
