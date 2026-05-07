package api

import (
	"bufio"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/jonathjan0397/strata-hosting-panel/agent/internal/files"
)

const maxTrafficScanBytes int64 = 100 << 20

type trafficDaySummary struct {
	Date           string `json:"date"`
	Requests       int    `json:"requests"`
	BandwidthBytes int64  `json:"bandwidth_bytes"`
	Status2xx      int    `json:"status_2xx"`
	Status3xx      int    `json:"status_3xx"`
	Status4xx      int    `json:"status_4xx"`
	Status5xx      int    `json:"status_5xx"`
}

// GET /v1/metrics/{username}/traffic?path=example.com.access.log&days=30
func handleTrafficSummary(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	path := r.URL.Query().Get("path")
	days, _ := strconv.Atoi(r.URL.Query().Get("days"))
	if days <= 0 || days > 90 {
		days = 30
	}

	abs, err := files.ResolveLogPath(username, path)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	summaries, err := summarizeTrafficLog(abs, days)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	respond(w, http.StatusOK, map[string]any{
		"path": path,
		"days": summaries,
	})
}

func summarizeTrafficLog(path string, days int) ([]trafficDaySummary, error) {
	file, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return []trafficDaySummary{}, nil
		}
		return nil, err
	}
	defer file.Close()

	info, err := file.Stat()
	if err != nil {
		return nil, err
	}
	if info.Size() > maxTrafficScanBytes {
		if _, err := file.Seek(-maxTrafficScanBytes, 2); err != nil {
			return nil, err
		}
	}

	cutoff := time.Now().AddDate(0, 0, -days+1)
	byDate := make(map[string]*trafficDaySummary)
	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 64*1024), 1024*1024)

	for scanner.Scan() {
		day, status, bytes, ok := parseAccessLogLine(scanner.Text())
		if !ok {
			continue
		}
		parsedDay, err := time.Parse("2006-01-02", day)
		if err != nil || parsedDay.Before(truncateDay(cutoff)) {
			continue
		}

		summary := byDate[day]
		if summary == nil {
			summary = &trafficDaySummary{Date: day}
			byDate[day] = summary
		}
		summary.Requests++
		summary.BandwidthBytes += bytes
		switch {
		case status >= 200 && status < 300:
			summary.Status2xx++
		case status >= 300 && status < 400:
			summary.Status3xx++
		case status >= 400 && status < 500:
			summary.Status4xx++
		case status >= 500 && status < 600:
			summary.Status5xx++
		}
	}
	if err := scanner.Err(); err != nil {
		return nil, err
	}

	result := make([]trafficDaySummary, 0, days)
	for i := days - 1; i >= 0; i-- {
		day := truncateDay(time.Now().AddDate(0, 0, -i)).Format("2006-01-02")
		if summary := byDate[day]; summary != nil {
			result = append(result, *summary)
		} else {
			result = append(result, trafficDaySummary{Date: day})
		}
	}
	return result, nil
}

func parseAccessLogLine(line string) (string, int, int64, bool) {
	leftBracket := strings.IndexByte(line, '[')
	rightBracket := strings.IndexByte(line, ']')
	if leftBracket < 0 || rightBracket <= leftBracket+11 {
		return "", 0, 0, false
	}

	timestamp := line[leftBracket+1 : rightBracket]
	parsed, err := time.Parse("02/Jan/2006:15:04:05 -0700", timestamp)
	if err != nil {
		return "", 0, 0, false
	}

	secondQuote := strings.LastIndexByte(line, '"')
	if secondQuote < 0 || secondQuote+2 >= len(line) {
		return "", 0, 0, false
	}
	parts := strings.Fields(line[secondQuote+1:])
	if len(parts) < 2 {
		return "", 0, 0, false
	}

	status, err := strconv.Atoi(parts[0])
	if err != nil {
		return "", 0, 0, false
	}
	var bytes int64
	if parts[1] != "-" {
		bytes, _ = strconv.ParseInt(parts[1], 10, 64)
	}

	return parsed.Format("2006-01-02"), status, bytes, true
}

func truncateDay(value time.Time) time.Time {
	year, month, day := value.Date()
	return time.Date(year, month, day, 0, 0, 0, 0, value.Location())
}
