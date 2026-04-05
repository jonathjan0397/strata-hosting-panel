package api

import (
	"bufio"
	"crypto/md5"
	"encoding/base64"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"

	"github.com/go-chi/chi/v5"
)

// GET /v1/accounts/{username}/ssh-keys
func handleSshKeyList(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	keys, err := listSshKeys(username)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	respond(w, http.StatusOK, keys)
}

// POST /v1/accounts/{username}/ssh-keys
func handleSshKeyAdd(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	var req struct {
		Name      string `json:"name"`
		PublicKey string `json:"public_key"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	fp, err := addSshKey(username, req.Name, req.PublicKey)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	respond(w, http.StatusCreated, map[string]string{"status": "added", "fingerprint": fp})
}

// DELETE /v1/accounts/{username}/ssh-keys/{fingerprint}
func handleSshKeyDelete(w http.ResponseWriter, r *http.Request) {
	username    := chi.URLParam(r, "username")
	fingerprint := chi.URLParam(r, "fingerprint")
	if err := deleteSshKey(username, fingerprint); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted"})
}

func sshKeyPath(username string) string {
	return filepath.Join("/var/www", username, ".ssh", "authorized_keys")
}

func listSshKeys(username string) ([]map[string]string, error) {
	path := sshKeyPath(username)
	f, err := os.Open(path)
	if os.IsNotExist(err) {
		return []map[string]string{}, nil
	}
	if err != nil {
		return nil, err
	}
	defer f.Close()
	var keys []map[string]string
	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		parts := strings.Fields(line)
		if len(parts) < 2 {
			continue
		}
		fp := sshKeyFingerprint(parts[1])
		comment := ""
		if len(parts) >= 3 {
			comment = strings.Join(parts[2:], " ")
		}
		keys = append(keys, map[string]string{
			"fingerprint": fp,
			"type":        parts[0],
			"comment":     comment,
		})
	}
	if keys == nil {
		keys = []map[string]string{}
	}
	return keys, scanner.Err()
}

func addSshKey(username, name, pubKey string) (string, error) {
	pubKey = strings.TrimSpace(pubKey)
	parts := strings.Fields(pubKey)
	if len(parts) < 2 {
		return "", fmt.Errorf("invalid public key format")
	}
	if _, err := base64.StdEncoding.DecodeString(parts[1]); err != nil {
		return "", fmt.Errorf("invalid public key encoding")
	}
	fp := sshKeyFingerprint(parts[1])
	path := sshKeyPath(username)
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return "", err
	}
	existing, _ := listSshKeys(username)
	for _, k := range existing {
		if k["fingerprint"] == fp {
			return "", fmt.Errorf("key already exists")
		}
	}
	line := fmt.Sprintf("%s %s %s\n", parts[0], parts[1], name)
	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0600)
	if err != nil {
		return "", err
	}
	defer f.Close()
	if _, err := f.WriteString(line); err != nil {
		return "", err
	}
	return fp, nil
}

func deleteSshKey(username, fingerprint string) error {
	path := sshKeyPath(username)
	data, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	var lines []string
	for _, line := range strings.Split(string(data), "\n") {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			lines = append(lines, line)
			continue
		}
		parts := strings.Fields(trimmed)
		if len(parts) >= 2 && sshKeyFingerprint(parts[1]) == fingerprint {
			continue
		}
		lines = append(lines, line)
	}
	return os.WriteFile(path, []byte(strings.Join(lines, "\n")), 0600)
}

func sshKeyFingerprint(b64key string) string {
	decoded, err := base64.StdEncoding.DecodeString(b64key)
	if err != nil {
		if len(b64key) > 16 {
			return b64key[:16]
		}
		return b64key
	}
	sum := md5.Sum(decoded)
	parts := make([]string, 16)
	for i, b := range sum {
		parts[i] = fmt.Sprintf("%02x", b)
	}
	return strings.Join(parts, ":")
}
