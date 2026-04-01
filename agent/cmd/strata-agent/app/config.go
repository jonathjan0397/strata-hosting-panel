package app

import (
	"fmt"
	"os"
	"strconv"
)

type Config struct {
	Port       int
	HMACSecret string
	TLSCert    string
	TLSKey     string
	NodeID     string
}

func loadConfig() (*Config, error) {
	cfg := &Config{
		Port:       8743,
		HMACSecret: env("STRATA_HMAC_SECRET", ""),
		TLSCert:    env("STRATA_TLS_CERT", "/etc/strata-agent/tls/cert.pem"),
		TLSKey:     env("STRATA_TLS_KEY", "/etc/strata-agent/tls/key.pem"),
		NodeID:     env("STRATA_NODE_ID", ""),
	}

	if portStr := os.Getenv("STRATA_PORT"); portStr != "" {
		p, err := strconv.Atoi(portStr)
		if err != nil {
			return nil, fmt.Errorf("invalid STRATA_PORT: %s", portStr)
		}
		cfg.Port = p
	}

	if cfg.HMACSecret == "" {
		return nil, fmt.Errorf("STRATA_HMAC_SECRET is required")
	}
	if cfg.NodeID == "" {
		return nil, fmt.Errorf("STRATA_NODE_ID is required")
	}

	return cfg, nil
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
