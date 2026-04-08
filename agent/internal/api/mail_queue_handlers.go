package api

import (
	"net/http"
	"os/exec"
	"regexp"
	"strconv"
	"strings"

	"github.com/go-chi/chi/v5"
)

var postfixQueueIDPattern = regexp.MustCompile(`^[A-F0-9]{5,}$`)
var postfixQueueLinePattern = regexp.MustCompile(`^([A-F0-9]+)[*!]?\s+(\d+)\s+(.+)$`)

type mailQueueEntry struct {
	ID      string `json:"id"`
	Size    int    `json:"size"`
	Arrival string `json:"arrival"`
	Sender  string `json:"sender"`
	Summary string `json:"summary"`
}

func handleMailQueue(w http.ResponseWriter, r *http.Request) {
	out, err := exec.Command("postqueue", "-p").CombinedOutput()
	if err != nil {
		http.Error(w, "postqueue failed: "+string(out), http.StatusInternalServerError)
		return
	}

	raw := string(out)
	entries := parsePostfixQueue(raw)

	respond(w, http.StatusOK, map[string]any{
		"count":   len(entries),
		"entries": entries,
		"raw":     raw,
	})
}

func handleMailQueueFlush(w http.ResponseWriter, r *http.Request) {
	out, err := exec.Command("postqueue", "-f").CombinedOutput()
	if err != nil {
		http.Error(w, "postqueue flush failed: "+string(out), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, map[string]string{
		"status": "flushed",
		"output": strings.TrimSpace(string(out)),
	})
}

func handleMailQueueDelete(w http.ResponseWriter, r *http.Request) {
	queueID := normalizePostfixQueueID(chi.URLParam(r, "queueID"))
	if queueID == "" {
		http.Error(w, "invalid queue id", http.StatusBadRequest)
		return
	}

	out, err := exec.Command("postsuper", "-d", queueID).CombinedOutput()
	if err != nil {
		http.Error(w, "postsuper delete failed: "+string(out), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, map[string]string{
		"status":   "deleted",
		"queue_id": queueID,
		"output":   strings.TrimSpace(string(out)),
	})
}

func handleMailQueueDeleteAll(w http.ResponseWriter, r *http.Request) {
	out, err := exec.Command("postsuper", "-d", "ALL").CombinedOutput()
	if err != nil {
		http.Error(w, "postsuper delete all failed: "+string(out), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, map[string]string{
		"status": "deleted_all",
		"output": strings.TrimSpace(string(out)),
	})
}

func normalizePostfixQueueID(queueID string) string {
	queueID = strings.ToUpper(strings.TrimSpace(queueID))
	queueID = strings.TrimSuffix(strings.TrimSuffix(queueID, "*"), "!")
	if !postfixQueueIDPattern.MatchString(queueID) {
		return ""
	}
	return queueID
}

func parsePostfixQueue(raw string) []mailQueueEntry {
	entries := []mailQueueEntry{}
	lines := strings.Split(raw, "\n")

	for i, line := range lines {
		line = strings.TrimSpace(line)
		matches := postfixQueueLinePattern.FindStringSubmatch(line)
		if len(matches) != 4 {
			continue
		}

		queueID := normalizePostfixQueueID(matches[1])
		if queueID == "" {
			continue
		}

		size, _ := strconv.Atoi(matches[2])
		summary := strings.TrimSpace(matches[3])
		sender := ""
		if i+1 < len(lines) {
			sender = strings.TrimSpace(lines[i+1])
			if strings.HasPrefix(sender, "(") {
				sender = ""
			}
		}

		entries = append(entries, mailQueueEntry{
			ID:      queueID,
			Size:    size,
			Arrival: summary,
			Sender:  sender,
			Summary: line,
		})
	}

	return entries
}
