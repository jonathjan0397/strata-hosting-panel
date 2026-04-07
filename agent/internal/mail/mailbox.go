package mail

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
)

var validEmail = regexp.MustCompile(`^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$`)

type MailboxRequest struct {
	Email    string `json:"email"`
	Password string `json:"password"`
}

type ForwarderRequest struct {
	Source      string `json:"source"`      // source@domain.com
	Destination string `json:"destination"` // dest@other.com
}

// CreateMailbox provisions a virtual mailbox in Postfix + Dovecot.
func CreateMailbox(req MailboxRequest) error {
	if !validEmail.MatchString(req.Email) {
		return fmt.Errorf("invalid email: %s", req.Email)
	}
	if len(req.Password) < 8 {
		return fmt.Errorf("password too short")
	}

	parts := strings.SplitN(req.Email, "@", 2)
	user, domain := parts[0], parts[1]

	// Hash password using doveadm
	hash, err := hashPassword(req.Password)
	if err != nil {
		return fmt.Errorf("hash password: %w", err)
	}

	// Create maildir
	mailDir := filepath.Join(mailBaseDir, domain, user)
	if err := os.MkdirAll(mailDir, 0700); err != nil {
		return fmt.Errorf("maildir: %w", err)
	}
	exec.Command("chown", "-R", "vmail:vmail", mailDir).Run()

	// Add to Postfix virtual_mailboxes: email   domain/user/
	mboxEntry := fmt.Sprintf("%s\t%s/%s/", req.Email, domain, user)
	if err := addLineUnique(virtualMboxF, mboxEntry); err != nil {
		return fmt.Errorf("virtual_mailboxes: %w", err)
	}
	if err := postmapFile(virtualMboxF); err != nil {
		return fmt.Errorf("postmap mailboxes: %w", err)
	}

	// Add to Dovecot virtual_users: email:{hash}:5000:5000::/var/mail/vhosts/domain/user::
	// UID/GID 5000 = vmail
	userEntry := fmt.Sprintf("%s:%s:5000:5000::/var/mail/vhosts/%s/%s::", req.Email, hash, domain, user)
	if err := updateDovecotUser(req.Email, userEntry); err != nil {
		return fmt.Errorf("dovecot users: %w", err)
	}

	reloadDovecot()
	exec.Command("postfix", "reload").Run()

	return nil
}

// DeleteMailbox removes a virtual mailbox.
func DeleteMailbox(email string) error {
	if !validEmail.MatchString(email) {
		return fmt.Errorf("invalid email: %s", email)
	}

	parts := strings.SplitN(email, "@", 2)
	user, domain := parts[0], parts[1]

	removeLineContaining(virtualMboxF, email)
	postmapFile(virtualMboxF)
	removeDovecotUser(email)
	reloadDovecot()

	// Remove maildir (irreversible — caller should confirm)
	os.RemoveAll(filepath.Join(mailBaseDir, domain, user))

	return nil
}

// ChangePassword updates the Dovecot password hash for an existing mailbox.
func ChangePassword(email, newPassword string) error {
	if !validEmail.MatchString(email) {
		return fmt.Errorf("invalid email: %s", email)
	}
	if len(newPassword) < 8 {
		return fmt.Errorf("password too short")
	}

	hash, err := hashPassword(newPassword)
	if err != nil {
		return err
	}

	parts := strings.SplitN(email, "@", 2)
	user, domain := parts[0], parts[1]

	newEntry := fmt.Sprintf("%s:%s:5000:5000::/var/mail/vhosts/%s/%s::", email, hash, domain, user)
	return updateDovecotUser(email, newEntry)
}

// CreateForwarder adds a virtual alias (email forwarder).
func CreateForwarder(req ForwarderRequest) error {
	if !validEmail.MatchString(req.Source) {
		return fmt.Errorf("invalid source: %s", req.Source)
	}
	if !validEmail.MatchString(req.Destination) {
		return fmt.Errorf("invalid destination: %s", req.Destination)
	}

	entry := fmt.Sprintf("%s\t%s", req.Source, req.Destination)
	if err := addLineUnique(virtualAliasF, entry); err != nil {
		return err
	}
	if err := postmapFile(virtualAliasF); err != nil {
		return err
	}
	exec.Command("postfix", "reload").Run()
	return nil
}

// DeleteForwarder removes a virtual alias.
func DeleteForwarder(source string) error {
	removeLineContaining(virtualAliasF, source+"\t")
	postmapFile(virtualAliasF)
	exec.Command("postfix", "reload").Run()
	return nil
}

// hashPassword uses doveadm to generate a SHA512-CRYPT hash.
func hashPassword(password string) (string, error) {
	out, err := exec.Command("doveadm", "pw", "-s", "SHA512-CRYPT", "-p", password).Output()
	if err != nil {
		return "", fmt.Errorf("doveadm pw: %w", err)
	}
	return strings.TrimSpace(string(out)), nil
}

// updateDovecotUser replaces or appends a user entry in the Dovecot passwd-file.
func updateDovecotUser(email, entry string) error {
	content, _ := os.ReadFile(dovecotUsersF)
	var kept []string
	for _, line := range strings.Split(string(content), "\n") {
		if line == "" {
			continue
		}
		if strings.HasPrefix(line, email+":") {
			continue // will be replaced
		}
		kept = append(kept, line)
	}
	kept = append(kept, entry)
	if err := os.WriteFile(dovecotUsersF, []byte(strings.Join(kept, "\n")+"\n"), 0640); err != nil {
		return err
	}
	_ = exec.Command("chown", "root:dovecot", dovecotUsersF).Run()
	return nil
}

func removeDovecotUser(email string) {
	content, err := os.ReadFile(dovecotUsersF)
	if err != nil {
		return
	}
	var kept []string
	for _, line := range strings.Split(string(content), "\n") {
		if !strings.HasPrefix(line, email+":") {
			kept = append(kept, line)
		}
	}
	if err := os.WriteFile(dovecotUsersF, []byte(strings.Join(kept, "\n")), 0640); err == nil {
		_ = exec.Command("chown", "root:dovecot", dovecotUsersF).Run()
	}
}
