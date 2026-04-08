package main

import (
	"crypto/tls"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"time"

	stratawebdav "github.com/jonathjan0397/strata-hosting-panel/agent/internal/webdav"
	"golang.org/x/net/webdav"
)

func main() {
	port := envInt("STRATA_WEBDAV_PORT", 2078)
	cert := env("STRATA_TLS_CERT", "/etc/strata-agent/tls/cert.pem")
	key := env("STRATA_TLS_KEY", "/etc/strata-agent/tls/key.pem")
	store := stratawebdav.NewStore(env("STRATA_WEBDAV_ACCOUNTS", stratawebdav.DefaultAccountsFile))
	locks := webdav.NewMemLS()

	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		username, password, ok := r.BasicAuth()
		if !ok {
			requireAuth(w)
			return
		}
		account, authenticated := store.Authenticate(username, password)
		if !authenticated {
			requireAuth(w)
			return
		}

		fileSystem, err := stratawebdav.NewFileSystem(account.HomeDir)
		if err != nil {
			http.Error(w, "web disk root is unavailable", http.StatusServiceUnavailable)
			return
		}

		dav := webdav.Handler{
			Prefix:     "/",
			FileSystem: fileSystem,
			LockSystem: locks,
		}
		dav.ServeHTTP(w, r)
	})

	server := &http.Server{
		Addr:              fmt.Sprintf(":%d", port),
		Handler:           handler,
		ReadTimeout:       30 * time.Second,
		ReadHeaderTimeout: 10 * time.Second,
		WriteTimeout:      120 * time.Second,
		IdleTimeout:       120 * time.Second,
		TLSConfig: &tls.Config{
			MinVersion: tls.VersionTLS12,
		},
	}

	log.Printf("strata-webdav listening on :%d", port)
	if err := server.ListenAndServeTLS(cert, key); err != nil && err != http.ErrServerClosed {
		log.Fatalf("listen: %v", err)
	}
}

func requireAuth(w http.ResponseWriter) {
	w.Header().Set("WWW-Authenticate", `Basic realm="Strata Web Disk"`)
	http.Error(w, "authentication required", http.StatusUnauthorized)
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}

func envInt(key string, fallback int) int {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return fallback
	}
	return parsed
}
