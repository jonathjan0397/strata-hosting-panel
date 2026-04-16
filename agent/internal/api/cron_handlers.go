package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	agentcron "github.com/jonathjan0397/strata-hosting-panel/agent/internal/cron"
)

func handleCronApply(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")

	var req struct {
		Jobs []agentcron.Job `json:"jobs"`
	}

	if !decodeJSON(w, r, &req) {
		return
	}

	if err := agentcron.ApplyManaged(username, req.Jobs); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}

	enabled := 0
	for _, job := range req.Jobs {
		if job.Enabled {
			enabled++
		}
	}

	respond(w, http.StatusOK, map[string]any{
		"status":        "applied",
		"username":      username,
		"enabled_count": enabled,
		"total_count":   len(req.Jobs),
	})
}
