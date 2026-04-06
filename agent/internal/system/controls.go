package system

import (
	"fmt"
	"os/exec"
)

// AllowedServiceControls is the definitive allowlist for service operations.
var AllowedServiceControls = map[string]bool{
	"nginx":      true,
	"apache2":    true,
	"php8.1-fpm": true,
	"php8.2-fpm": true,
	"php8.3-fpm": true,
	"php8.4-fpm": true,
	"postfix":    true,
	"dovecot":    true,
	"rspamd":     true,
	"opendkim":   true,
	"pdns":       true,
	"mariadb":    true,
	"mysql":      true,
	"pure-ftpd":  true,
	"fail2ban":   true,
	"redis":      true,
	"redis-server": true,
}

type ServiceStatus struct {
	Name        string `json:"name"`
	Active      bool   `json:"active"`
	Enabled     bool   `json:"enabled"`
	Description string `json:"description"`
}

func GetServiceStatuses() []ServiceStatus {
	services := []string{
		"nginx", "apache2",
		"php8.1-fpm", "php8.2-fpm", "php8.3-fpm", "php8.4-fpm",
		"postfix", "dovecot", "rspamd", "opendkim",
		"pdns", "mariadb", "mysql", "pure-ftpd", "fail2ban",
		"redis", "redis-server",
	}

	result := make([]ServiceStatus, 0, len(services))
	for _, svc := range services {
		active := isActive(svc)
		enabled := isEnabled(svc)
		result = append(result, ServiceStatus{
			Name:    svc,
			Active:  active,
			Enabled: enabled,
		})
	}
	return result
}

func ServiceAction(name, action string) error {
	if !AllowedServiceControls[name] {
		return fmt.Errorf("service not in allowlist: %s", name)
	}
	switch action {
	case "start", "stop", "restart", "reload":
		// all allowed
	default:
		return fmt.Errorf("action not allowed: %s", action)
	}
	return exec.Command("systemctl", action, name).Run()
}

func isActive(name string) bool {
	err := exec.Command("systemctl", "is-active", "--quiet", name).Run()
	return err == nil
}

func isEnabled(name string) bool {
	err := exec.Command("systemctl", "is-enabled", "--quiet", name).Run()
	return err == nil
}
