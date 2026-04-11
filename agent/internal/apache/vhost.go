package apache

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"text/template"

	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/webroot"
)

const (
	sitesAvailable = "/etc/apache2/sites-available"
	sitesEnabled   = "/etc/apache2/sites-enabled"
)

var validDomain = regexp.MustCompile(`^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$`)

type VhostConfig struct {
	Domain           string
	Username         string
	DocumentRoot     string
	PHPVersion       string
	PHPSocket        string
	SSLEnabled       bool
	SSLCert          string
	SSLKey           string
	CustomDirectives string
}

var vhostTemplate = template.Must(template.New("apachevhost").Parse(`# Strata Hosting Panel managed — do not edit manually
# Domain: {{.Domain}} | Account: {{.Username}}
{{if .SSLEnabled}}
<VirtualHost *:80>
    ServerName {{.Domain}}
    ServerAlias www.{{.Domain}}
    Redirect permanent / https://{{.Domain}}/
</VirtualHost>

<VirtualHost *:443>
    ServerName {{.Domain}}
    ServerAlias www.{{.Domain}}

    SSLEngine on
    SSLCertificateFile    {{.SSLCert}}
    SSLCertificateKeyFile {{.SSLKey}}
    SSLProtocol           all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite        ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"
{{else}}
<VirtualHost *:80>
    ServerName {{.Domain}}
    ServerAlias www.{{.Domain}}
{{end}}
    DocumentRoot {{.DocumentRoot}}

    CustomLog /home/{{.Username}}/logs/{{.Domain}}.access.log combined
    ErrorLog  /home/{{.Username}}/logs/{{.Domain}}.error.log

    <Directory {{.DocumentRoot}}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # PHP-FPM via Unix socket
    <FilesMatch \.php$>
        SetHandler "proxy:unix:{{.PHPSocket}}|fcgi://localhost"
    </FilesMatch>

    # Security: deny sensitive files
    <FilesMatch "\.(env|log|sql|sh|git)$">
        Require all denied
    </FilesMatch>

    {{if .CustomDirectives}}
    # Custom directives
    {{.CustomDirectives}}
    {{end}}
</VirtualHost>
`))

func WriteVhost(cfg VhostConfig) error {
	if !validDomain.MatchString(cfg.Domain) {
		return fmt.Errorf("invalid domain: %s", cfg.Domain)
	}
	if cfg.PHPSocket == "" {
		cfg.PHPSocket = fmt.Sprintf("/run/php/php%s-fpm-%s.sock", cfg.PHPVersion, cfg.Username)
	}
	if err := webroot.EnsureDefaultIndex(cfg.Username, cfg.Domain, cfg.DocumentRoot); err != nil {
		return err
	}

	availPath := filepath.Join(sitesAvailable, cfg.Domain+".conf")
	f, err := os.OpenFile(availPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0644)
	if err != nil {
		return fmt.Errorf("write vhost: %w", err)
	}
	defer f.Close()

	if err := vhostTemplate.Execute(f, cfg); err != nil {
		return fmt.Errorf("render vhost: %w", err)
	}

	// Enable site via a2ensite
	if out, err := exec.Command("a2ensite", cfg.Domain+".conf").CombinedOutput(); err != nil {
		return fmt.Errorf("a2ensite: %w: %s", err, string(out))
	}

	return TestAndReload()
}

func RemoveVhost(domain string) error {
	if !validDomain.MatchString(domain) {
		return fmt.Errorf("invalid domain: %s", domain)
	}
	exec.Command("a2dissite", domain+".conf").Run() //nolint:errcheck
	os.Remove(filepath.Join(sitesAvailable, domain+".conf"))
	return TestAndReload()
}

func TestAndReload() error {
	if out, err := exec.Command("apache2ctl", "configtest").CombinedOutput(); err != nil {
		return fmt.Errorf("apache2 config test failed: %s", string(out))
	}
	return exec.Command("systemctl", "reload", "apache2").Run()
}
