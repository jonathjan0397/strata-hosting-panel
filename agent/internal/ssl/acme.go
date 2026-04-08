package ssl

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

const (
	acmeBin  = "/root/.acme.sh/acme.sh"
	certBase = "/etc/ssl/strata"
)

type IssueRequest struct {
	Domain   string `json:"domain"`
	Wildcard bool   `json:"wildcard"`
}

type CertPaths struct {
	CertFile  string `json:"cert_file"`
	KeyFile   string `json:"key_file"`
	ChainFile string `json:"chain_file"`
}

func CertDir(domain string) string {
	return filepath.Join(certBase, domain)
}

func Paths(domain string) CertPaths {
	dir := CertDir(domain)
	return CertPaths{
		CertFile:  filepath.Join(dir, "cert.pem"),
		KeyFile:   filepath.Join(dir, "privkey.pem"),
		ChainFile: filepath.Join(dir, "fullchain.pem"),
	}
}

// Issue requests a certificate via acme.sh.
// Standard certs use the Nginx webroot challenge; wildcard certs use PowerDNS.
func Issue(req IssueRequest) (*CertPaths, error) {
	certDir := CertDir(req.Domain)
	if err := os.MkdirAll(certDir, 0700); err != nil {
		return nil, fmt.Errorf("mkdir cert dir: %w", err)
	}

	args := []string{"--issue", "-d", req.Domain, "--keylength", "4096", "--force"}
	env := append(os.Environ(), "HOME=/root")
	if req.Wildcard {
		pdnsURL := strings.TrimSpace(os.Getenv("STRATA_PDNS_URL"))
		pdnsToken := strings.TrimSpace(os.Getenv("STRATA_PDNS_API_KEY"))
		if pdnsURL == "" || pdnsToken == "" {
			return nil, fmt.Errorf("wildcard SSL requires STRATA_PDNS_URL and STRATA_PDNS_API_KEY")
		}

		args = append(args, "--dns", "dns_pdns", "-d", "*."+req.Domain)
		env = append(env,
			"PDNS_Url="+pdnsURL,
			"PDNS_ServerId=localhost",
			"PDNS_Token="+pdnsToken,
		)
	} else {
		args = append(args, "--nginx")
	}

	cmd := exec.Command(acmeBin, args...)
	cmd.Env = env
	if out, err := cmd.CombinedOutput(); err != nil {
		return nil, fmt.Errorf("acme.sh issue failed: %w\n%s", err, string(out))
	}

	paths := Paths(req.Domain)

	installArgs := []string{
		"--install-cert", "-d", req.Domain,
		"--cert-file", paths.CertFile,
		"--key-file", paths.KeyFile,
		"--fullchain-file", paths.ChainFile,
		"--reloadcmd", "systemctl reload nginx",
	}
	installCmd := exec.Command(acmeBin, installArgs...)
	installCmd.Env = append(os.Environ(), "HOME=/root")
	if out, err := installCmd.CombinedOutput(); err != nil {
		return nil, fmt.Errorf("acme.sh install-cert failed: %w\n%s", err, string(out))
	}

	return &paths, nil
}

// Remove revokes and removes a certificate.
func Remove(domain string) error {
	cmd := exec.Command(acmeBin, "--remove", "-d", domain)
	cmd.Env = append(os.Environ(), "HOME=/root")
	cmd.Run() // best-effort revoke

	return os.RemoveAll(CertDir(domain))
}
