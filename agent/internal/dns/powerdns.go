package dns

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
)

var (
	pdnsURL    = envOrDefault("STRATA_PDNS_URL", "http://127.0.0.1:8053")
	pdnsAPIKey = os.Getenv("STRATA_PDNS_API_KEY")
	serverID   = "localhost"
)

func envOrDefault(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

// Zone represents a PowerDNS zone with its resource record sets.
type Zone struct {
	ID      string  `json:"id"`
	Name    string  `json:"name"`
	Kind    string  `json:"kind"`
	DNSSec  bool    `json:"dnssec"`
	RRSets  []RRSet `json:"rrsets,omitempty"`
}

// RRSet is a DNS resource record set (one name+type combination).
type RRSet struct {
	Name       string    `json:"name"`
	Type       string    `json:"type"`
	TTL        int       `json:"ttl"`
	ChangeType string    `json:"changetype,omitempty"`
	Records    []Record  `json:"records,omitempty"`
	Comments   []Comment `json:"comments,omitempty"`
}

// Record is an individual DNS record value within an RRSet.
type Record struct {
	Content  string `json:"content"`
	Disabled bool   `json:"disabled"`
}

// Comment is an optional annotation on an RRSet.
type Comment struct {
	Content string `json:"content"`
	Account string `json:"account"`
}

// canonical ensures a DNS name ends with a trailing dot.
func canonical(name string) string {
	if !strings.HasSuffix(name, ".") {
		return name + "."
	}
	return name
}

func pdnsRequest(method, path string, body any) ([]byte, int, error) {
	var r io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, 0, err
		}
		r = bytes.NewReader(b)
	}
	req, err := http.NewRequest(method, pdnsURL+"/api/v1"+path, r)
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("X-API-Key", pdnsAPIKey)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, 0, fmt.Errorf("pdns unreachable: %w", err)
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(resp.Body)
	return data, resp.StatusCode, nil
}

// CreateZone creates a new authoritative DNS zone in PowerDNS.
func CreateZone(domain string) error {
	name := canonical(domain)
	zone := map[string]any{
		"name":        name,
		"kind":        "Native",
		"nameservers": []string{},
		"rrsets":      []any{},
	}
	data, status, err := pdnsRequest("POST", "/servers/"+serverID+"/zones", zone)
	if err != nil {
		return err
	}
	if status == 422 {
		// Zone already exists — treat as success.
		return nil
	}
	if status != 201 {
		return fmt.Errorf("pdns create zone: status %d: %s", status, string(data))
	}
	return nil
}

// DeleteZone removes a DNS zone from PowerDNS.
func DeleteZone(domain string) error {
	name := canonical(domain)
	_, status, err := pdnsRequest("DELETE", "/servers/"+serverID+"/zones/"+name, nil)
	if err != nil {
		return err
	}
	if status == 404 {
		return nil // Already gone.
	}
	if status != 204 {
		return fmt.Errorf("pdns delete zone: status %d", status)
	}
	return nil
}

// ZoneSummary is a lightweight zone entry returned by ListZones.
type ZoneSummary struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Kind string `json:"kind"`
}

// ListZones returns all zones from PowerDNS.
func ListZones() ([]ZoneSummary, error) {
	data, status, err := pdnsRequest("GET", "/servers/"+serverID+"/zones", nil)
	if err != nil {
		return nil, err
	}
	if status != 200 {
		return nil, fmt.Errorf("pdns list zones: status %d: %s", status, string(data))
	}
	var zones []ZoneSummary
	if err := json.Unmarshal(data, &zones); err != nil {
		return nil, err
	}
	return zones, nil
}

// GetZone retrieves zone details including all RRsets.
func GetZone(domain string) (*Zone, error) {
	name := canonical(domain)
	data, status, err := pdnsRequest("GET", "/servers/"+serverID+"/zones/"+name, nil)
	if err != nil {
		return nil, err
	}
	if status == 404 {
		return nil, fmt.Errorf("zone not found: %s", domain)
	}
	if status != 200 {
		return nil, fmt.Errorf("pdns get zone: status %d", status)
	}
	var z Zone
	if err := json.Unmarshal(data, &z); err != nil {
		return nil, err
	}
	return &z, nil
}

// UpsertRecord creates or replaces an RRset in a zone.
// name may be relative (e.g. "@", "www") or absolute with trailing dot.
func UpsertRecord(domain, name, recType string, ttl int, contents []string) error {
	zoneName := canonical(domain)

	// Build the fully-qualified record name.
	var recName string
	if name == "@" || name == "" {
		recName = zoneName
	} else if strings.HasSuffix(name, ".") {
		recName = name
	} else {
		recName = name + "." + zoneName
	}

	records := make([]Record, len(contents))
	for i, c := range contents {
		records[i] = Record{Content: c}
	}
	payload := map[string]any{
		"rrsets": []any{
			map[string]any{
				"name":       recName,
				"type":       recType,
				"ttl":        ttl,
				"changetype": "REPLACE",
				"records":    records,
			},
		},
	}
	data, status, err := pdnsRequest("PATCH", "/servers/"+serverID+"/zones/"+zoneName, payload)
	if err != nil {
		return err
	}
	if status != 204 {
		return fmt.Errorf("pdns upsert record: status %d: %s", status, string(data))
	}
	return nil
}

// DeleteRecord removes an RRset from a zone.
func DeleteRecord(domain, name, recType string) error {
	zoneName := canonical(domain)

	var recName string
	if name == "@" || name == "" {
		recName = zoneName
	} else if strings.HasSuffix(name, ".") {
		recName = name
	} else {
		recName = name + "." + zoneName
	}

	payload := map[string]any{
		"rrsets": []any{
			map[string]any{
				"name":       recName,
				"type":       recType,
				"changetype": "DELETE",
			},
		},
	}
	data, status, err := pdnsRequest("PATCH", "/servers/"+serverID+"/zones/"+zoneName, payload)
	if err != nil {
		return err
	}
	if status != 204 {
		return fmt.Errorf("pdns delete record: status %d: %s", status, string(data))
	}
	return nil
}
