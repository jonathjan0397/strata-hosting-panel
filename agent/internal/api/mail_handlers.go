package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-panel/agent/internal/mail"
)

func handleMailDomainProvision(w http.ResponseWriter, r *http.Request) {
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

	dkimPubKey, err := mail.ProvisionDomain(req.Domain)
	if err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}

	serverIP := mail.ServerIP()

	respond(w, http.StatusCreated, map[string]string{
		"status":       "provisioned",
		"domain":       req.Domain,
		"dkim_pubkey":  dkimPubKey,
		"spf_record":   mail.SPFRecord(serverIP),
		"dmarc_record": mail.DMARCRecord(req.Domain),
		"server_ip":    serverIP,
	})
}

func handleMailDomainDeprovision(w http.ResponseWriter, r *http.Request) {
	domain := chi.URLParam(r, "domain")
	if err := mail.DeprovisionDomain(domain); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deprovisioned", "domain": domain})
}

func handleMailboxCreate(w http.ResponseWriter, r *http.Request) {
	var req mail.MailboxRequest
	if !decodeJSON(w, r, &req) {
		return
	}
	if err := mail.CreateMailbox(req); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusCreated, map[string]string{"status": "created", "email": req.Email})
}

func handleMailboxDelete(w http.ResponseWriter, r *http.Request) {
	email := chi.URLParam(r, "email")
	if err := mail.DeleteMailbox(email); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "email": email})
}

func handleMailboxPassword(w http.ResponseWriter, r *http.Request) {
	email := chi.URLParam(r, "email")
	var req struct {
		Password string `json:"password"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}
	if err := mail.ChangePassword(email, req.Password); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "updated", "email": email})
}

func handleForwarderCreate(w http.ResponseWriter, r *http.Request) {
	var req mail.ForwarderRequest
	if !decodeJSON(w, r, &req) {
		return
	}
	if err := mail.CreateForwarder(req); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusCreated, map[string]string{
		"status":      "created",
		"source":      req.Source,
		"destination": req.Destination,
	})
}

func handleForwarderDelete(w http.ResponseWriter, r *http.Request) {
	source := chi.URLParam(r, "source")
	if err := mail.DeleteForwarder(source); err != nil {
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}
	respond(w, http.StatusOK, map[string]string{"status": "deleted", "source": source})
}
