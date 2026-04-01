package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-panel/agent/internal/dns"
)

func handleDNSCreateZone(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Domain string `json:"domain"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.Domain == "" {
		http.Error(w, "domain required", http.StatusBadRequest)
		return
	}
	if err := dns.CreateZone(req.Domain); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusCreated, map[string]string{"status": "created", "domain": req.Domain})
}

func handleDNSDeleteZone(w http.ResponseWriter, r *http.Request) {
	domain := chi.URLParam(r, "domain")
	if err := dns.DeleteZone(domain); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "domain": domain})
}

func handleDNSGetZone(w http.ResponseWriter, r *http.Request) {
	domain := chi.URLParam(r, "domain")
	zone, err := dns.GetZone(domain)
	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, zone)
}

func handleDNSUpsertRecord(w http.ResponseWriter, r *http.Request) {
	domain := chi.URLParam(r, "domain")
	var req struct {
		Name     string   `json:"name"`
		Type     string   `json:"type"`
		TTL      int      `json:"ttl"`
		Contents []string `json:"contents"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if req.TTL == 0 {
		req.TTL = 300
	}
	if err := dns.UpsertRecord(domain, req.Name, req.Type, req.TTL, req.Contents); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "ok"})
}

func handleDNSDeleteRecord(w http.ResponseWriter, r *http.Request) {
	domain := chi.URLParam(r, "domain")
	var req struct {
		Name string `json:"name"`
		Type string `json:"type"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if err := dns.DeleteRecord(domain, req.Name, req.Type); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted"})
}
