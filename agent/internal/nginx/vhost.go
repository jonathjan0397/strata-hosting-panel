package nginx

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
	sitesAvailable = "/etc/nginx/sites-available"
	sitesEnabled   = "/etc/nginx/sites-enabled"
)

var validDomain = regexp.MustCompile(`^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$`)

type VhostConfig struct {
	WebServer        string `json:"web_server"` // "nginx" (default) or "apache" — handled by dispatcher in api layer
	Domain           string `json:"domain"`
	Username         string `json:"username"`
	DocumentRoot     string `json:"document_root"`
	PHPVersion       string `json:"php_version"`
	PHPSocket        string `json:"php_socket"`
	SSLEnabled       bool   `json:"ssl_enabled"`
	SSLCert          string `json:"ssl_cert"`
	SSLKey           string `json:"ssl_key"`
	CustomDirectives string `json:"custom_directives"`
}

var vhostTemplate = template.Must(template.New("vhost").Parse(`# Strata Hosting Panel managed — do not edit manually
# Domain: {{.Domain}} | Account: {{.Username}}
{{if .SSLEnabled}}
server {
    listen 80;
    server_name {{.Domain}} www.{{.Domain}};
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name {{.Domain}} www.{{.Domain}};

    ssl_certificate     {{.SSLCert}};
    ssl_certificate_key {{.SSLKey}};
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
{{else}}
server {
    listen 80;
    server_name {{.Domain}} www.{{.Domain}};
{{end}}
    root  {{.DocumentRoot}};
    index index.php index.html;

    access_log /home/{{.Username}}/logs/{{.Domain}}.access.log;
    error_log  /home/{{.Username}}/logs/{{.Domain}}.error.log;

    client_max_body_size 64M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:{{.PHPSocket}};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   $realpath_root;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.ht    { deny all; }
    location ~ /\.git   { deny all; }
    location ~ \.(env|log|sql|sh)$ { deny all; }

    {{if .CustomDirectives}}
    # Custom directives
    {{.CustomDirectives}}
    {{end}}
}
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

	// Enable site
	enabledPath := filepath.Join(sitesEnabled, cfg.Domain+".conf")
	os.Remove(enabledPath)
	if err := os.Symlink(availPath, enabledPath); err != nil {
		return fmt.Errorf("enable vhost: %w", err)
	}

	return TestAndReload()
}

func RemoveVhost(domain string) error {
	if !validDomain.MatchString(domain) {
		return fmt.Errorf("invalid domain: %s", domain)
	}
	os.Remove(filepath.Join(sitesEnabled, domain+".conf"))
	os.Remove(filepath.Join(sitesAvailable, domain+".conf"))
	return TestAndReload()
}

func TestAndReload() error {
	if out, err := exec.Command("nginx", "-t").CombinedOutput(); err != nil {
		return fmt.Errorf("nginx config test failed: %s", string(out))
	}
	return exec.Command("systemctl", "reload", "nginx").Run()
}
