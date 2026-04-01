package api

import (
	"github.com/go-chi/chi/v5"
)

func Routes() chi.Router {
	r := chi.NewRouter()

	// Health + version
	r.Get("/health", handleHealth)
	r.Get("/version", handleVersion)

	// System info (CPU, RAM, disk, load)
	r.Get("/system/info", handleSystemInfo)

	// Service management
	r.Get("/services", handleServiceList)
	r.Post("/services/{name}/restart", handleServiceRestart)
	r.Post("/services/{name}/reload", handleServiceReload)

	// Nginx vhost management
	r.Post("/nginx/vhost", handleNginxVhostCreate)
	r.Delete("/nginx/vhost/{domain}", handleNginxVhostDelete)
	r.Post("/nginx/reload", handleNginxReload)

	// PHP-FPM pool management
	r.Post("/php/pool", handlePHPPoolCreate)
	r.Delete("/php/pool/{user}", handlePHPPoolDelete)
	r.Put("/php/pool/{user}/version", handlePHPPoolVersionSet)

	// SSL certificate management
	r.Post("/ssl/issue", handleSSLIssue)
	r.Delete("/ssl/{domain}", handleSSLDelete)

	// Self-upgrade
	r.Post("/agent/upgrade", handleAgentUpgrade)

	return r
}
