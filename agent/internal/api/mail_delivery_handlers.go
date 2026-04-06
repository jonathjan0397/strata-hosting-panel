package api

import (
	"net/http"
	"strconv"
	"strings"

	"github.com/jonathjan0397/strata-panel/agent/internal/system"
)

// GET /v1/mail/delivery
func handleMailDeliveryLog(w http.ResponseWriter, r *http.Request) {
	query := strings.TrimSpace(strings.ToLower(r.URL.Query().Get("query")))
	if query == "" {
		http.Error(w, "query is required", http.StatusBadRequest)
		return
	}

	service := strings.TrimSpace(strings.ToLower(r.URL.Query().Get("service")))
	if service == "" {
		service = "postfix"
	}
	if service != "postfix" && service != "dovecot" && service != "all" {
		http.Error(w, "service must be postfix, dovecot, or all", http.StatusBadRequest)
		return
	}

	lines, _ := strconv.Atoi(r.URL.Query().Get("lines"))
	if lines <= 0 || lines > 500 {
		lines = 200
	}

	results := []string{}
	checked := []string{}

	for _, target := range deliveryTargets(service) {
		logLines, err := system.ReadLog(target, 500)
		if err != nil {
			http.Error(w, err.Error(), http.StatusInternalServerError)
			return
		}
		checked = append(checked, target)

		for _, line := range logLines {
			if strings.Contains(strings.ToLower(line), query) {
				results = append(results, line)
			}
		}
	}

	if len(results) > lines {
		results = results[len(results)-lines:]
	}

	respond(w, http.StatusOK, map[string]any{
		"query": query,
		"service": service,
		"checked_logs": checked,
		"count": len(results),
		"entries": results,
	})
}

func deliveryTargets(service string) []string {
	switch service {
	case "dovecot":
		return []string{"dovecot"}
	case "all":
		return []string{"postfix", "dovecot"}
	default:
		return []string{"postfix"}
	}
}
