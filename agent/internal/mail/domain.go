package mail

import (
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

const (
	postfixDir      = "/etc/postfix"
	virtualDomainsF = "/etc/postfix/virtual_domains"
	virtualMboxF    = "/etc/postfix/virtual_mailboxes"
	virtualAliasF   = "/etc/postfix/virtual_aliases"
	mailBaseDir     = "/var/mail/vhosts"
	dovecotUsersF   = "/etc/dovecot/virtual_users"
)

func ensureMailBase() error {
	if err := os.MkdirAll(mailBaseDir, 0750); err != nil {
		return err
	}
	_ = exec.Command("chown", "vmail:vmail", mailBaseDir).Run()
	_ = os.Chmod(mailBaseDir, 0750)
	return nil
}

// ProvisionDomain adds a domain to Postfix virtual_mailbox_domains and
// creates its mail spool directory. Returns DKIM public key for DNS.
func ProvisionDomain(domain string) (dkimPublicKey string, err error) {
	if err := ensureMailBase(); err != nil {
		return "", fmt.Errorf("mail base: %w", err)
	}
	if err := os.MkdirAll(filepath.Join(mailBaseDir, domain), 0770); err != nil {
		return "", fmt.Errorf("mail dir: %w", err)
	}
	// vmail user owns mail directories
	exec.Command("chown", "-R", "vmail:vmail", filepath.Join(mailBaseDir, domain)).Run()

	if err := addMapEntryUnique(virtualDomainsF, domain, "OK"); err != nil {
		return "", fmt.Errorf("virtual_domains: %w", err)
	}

	if err := postmapFile(virtualDomainsF); err != nil {
		return "", fmt.Errorf("postmap domains: %w", err)
	}

	// Generate DKIM key for domain
	pubKey, err := GenerateDKIMKey(domain)
	if err != nil {
		// Non-fatal: log and continue, admin can re-trigger
		_ = err
	}

	// Reload Postfix
	exec.Command("postfix", "reload").Run()

	return pubKey, nil
}

// DeprovisionDomain removes a domain from all mail config files.
func DeprovisionDomain(domain string) error {
	removeLineContaining(virtualDomainsF, domain)
	removeLinesContaining(virtualMboxF, "@"+domain)
	removeLinesContaining(virtualAliasF, "@"+domain)
	removeDovecotUsersByDomain(domain)
	RemoveDKIMKey(domain)

	postmapFile(virtualDomainsF)
	postmapFile(virtualMboxF)
	postmapFile(virtualAliasF)
	reloadDovecot()

	exec.Command("postfix", "reload").Run()

	// Remove mail spool (soft — don't fail if it errors)
	os.RemoveAll(filepath.Join(mailBaseDir, domain))

	return nil
}

// ServerIP returns the primary public IP of this node.
func ServerIP() string {
	conn, err := net.Dial("udp", "8.8.8.8:80")
	if err != nil {
		return ""
	}
	defer conn.Close()
	return conn.LocalAddr().(*net.UDPAddr).IP.String()
}

// SPFRecord returns a standard SPF TXT record value for the given IP.
func SPFRecord(serverIP string) string {
	if serverIP == "" {
		return "v=spf1 a mx -all"
	}
	return fmt.Sprintf("v=spf1 a mx ip4:%s -all", serverIP)
}

// DMARCRecord returns a standard DMARC TXT record value.
func DMARCRecord(domain string) string {
	return fmt.Sprintf(
		"v=DMARC1; p=reject; pct=100; rua=mailto:postmaster@%s; aspf=r; adkim=r",
		domain,
	)
}

// ── File helpers ──────────────────────────────────────────────────────────────

func addLineUnique(path, line string) error {
	content, _ := os.ReadFile(path)
	lines := strings.Split(strings.TrimSpace(string(content)), "\n")
	for _, l := range lines {
		if strings.TrimSpace(l) == line {
			return nil // already present
		}
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0644)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = fmt.Fprintln(f, line)
	return err
}

func addMapEntryUnique(path, key, value string) error {
	content, _ := os.ReadFile(path)
	var kept []string
	for _, raw := range strings.Split(string(content), "\n") {
		line := strings.TrimSpace(raw)
		if line == "" {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) > 0 && fields[0] == key {
			if len(fields) > 1 && fields[1] == value {
				return nil
			}
			continue
		}
		kept = append(kept, raw)
	}
	kept = append(kept, fmt.Sprintf("%s\t%s", key, value))
	data := strings.Join(kept, "\n") + "\n"
	return os.WriteFile(path, []byte(data), 0644)
}

func removeLineContaining(path, substr string) {
	content, err := os.ReadFile(path)
	if err != nil {
		return
	}
	var kept []string
	for _, line := range strings.Split(string(content), "\n") {
		if !strings.Contains(line, substr) {
			kept = append(kept, line)
		}
	}
	os.WriteFile(path, []byte(strings.Join(kept, "\n")), 0644)
}

func removeLinesContaining(path, substr string) {
	removeLineContaining(path, substr)
}

func removeDovecotUsersByDomain(domain string) {
	content, err := os.ReadFile(dovecotUsersF)
	if err != nil {
		return
	}

	var kept []string
	suffix := "@" + domain + ":"
	for _, line := range strings.Split(string(content), "\n") {
		if line == "" {
			continue
		}
		if strings.Contains(line, suffix) {
			continue
		}
		kept = append(kept, line)
	}

	if err := os.WriteFile(dovecotUsersF, []byte(strings.Join(kept, "\n")+"\n"), 0640); err == nil {
		_ = exec.Command("chown", "root:dovecot", dovecotUsersF).Run()
	}
}

func postmapFile(path string) error {
	return exec.Command("postmap", path).Run()
}

func reloadDovecot() {
	exec.Command("systemctl", "reload", "dovecot").Run()
}
