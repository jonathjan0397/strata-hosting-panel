package api

import (
	"fmt"
	"log"
	"net/http"
	"regexp"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/php"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/system"
)

var phpSizeRe = regexp.MustCompile(`^\d+[KMGkmg]?$`)

// PUT /php/pool/{user}/settings
// Updates PHP-FPM pool limits for the given account and reloads php-fpm.
// Respond first so reloading the same php-fpm version cannot sever the
// panel's own FastCGI request on the primary node.
func handlePHPPoolSettings(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "user")

	var req struct {
		PHPVersion  string `json:"php_version"`
		UploadMax   string `json:"upload_max"`
		PostMax     string `json:"post_max"`
		MemoryLimit string `json:"memory_limit"`
		MaxExecTime int    `json:"max_exec_time"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.PHPVersion == "" {
		http.Error(w, "php_version is required", http.StatusBadRequest)
		return
	}

	// Validate size strings to prevent config injection.
	for field, val := range map[string]string{
		"upload_max":   req.UploadMax,
		"post_max":     req.PostMax,
		"memory_limit": req.MemoryLimit,
	} {
		if val != "" && !phpSizeRe.MatchString(val) {
			http.Error(w, "invalid "+field+" value", http.StatusBadRequest)
			return
		}
	}

	cfg := php.PoolConfig{
		Username:    username,
		PHPVersion:  req.PHPVersion,
		MaxChildren: 5,
		UploadMax:   phpOrDefault(req.UploadMax, "64M"),
		PostMax:     phpOrDefault(req.PostMax, "64M"),
		MemoryLimit: phpOrDefault(req.MemoryLimit, "256M"),
		MaxExecTime: req.MaxExecTime,
	}
	if cfg.MaxExecTime <= 0 {
		cfg.MaxExecTime = 30
	}

	if err := php.WritePool(cfg); err != nil {
		http.Error(w, "write pool: "+err.Error(), http.StatusInternalServerError)
		return
	}

	fpmService := fmt.Sprintf("php%s-fpm", req.PHPVersion)
	respondAndFlush(w, http.StatusOK, map[string]string{"status": "ok"})

	go func(service string) {
		time.Sleep(350 * time.Millisecond)

		if err := system.ServiceAction(service, "reload"); err != nil {
			log.Printf("php settings: reload %s failed after response: %v", service, err)
		}
	}(fpmService)
}

func phpOrDefault(val, def string) string {
	if val == "" {
		return def
	}
	return val
}
