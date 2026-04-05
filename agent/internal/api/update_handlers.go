package api

import (
	"bufio"
	"net/http"
	"os/exec"
	"strings"
	"time"
)

type updatePackage struct {
	Name       string `json:"name"`
	NewVersion string `json:"new_version"`
	OldVersion string `json:"old_version"`
}

// GET /v1/system/updates — dry-run apt-get upgrade and return available packages
func handleUpdatesList(w http.ResponseWriter, r *http.Request) {
	out, err := exec.Command("apt-get", "-s", "upgrade").Output()
	if err != nil {
		http.Error(w, "apt-get error: "+err.Error(), http.StatusInternalServerError)
		return
	}

	var packages []updatePackage
	scanner := bufio.NewScanner(strings.NewReader(string(out)))
	for scanner.Scan() {
		line := scanner.Text()
		if !strings.HasPrefix(line, "Inst ") {
			continue
		}
		parts := strings.Fields(line)
		// Inst name [old_version] (new_version ...)
		pkg := updatePackage{Name: parts[1]}
		if len(parts) >= 3 {
			pkg.OldVersion = strings.Trim(parts[2], "[]")
		}
		if len(parts) >= 4 {
			pkg.NewVersion = strings.Trim(parts[3], "()")
		}
		packages = append(packages, pkg)
	}

	if packages == nil {
		packages = []updatePackage{}
	}

	respond(w, http.StatusOK, map[string]interface{}{
		"count":    len(packages),
		"packages": packages,
	})
}

// POST /v1/system/updates — apply available updates (safe upgrade, no dist-upgrade)
func handleUpdatesApply(w http.ResponseWriter, r *http.Request) {
	// Refresh package index first so upgrade doesn't fail with stale metadata
	updateCmd := exec.Command("apt-get", "update", "-q")
	updateCmd.Env = append(updateCmd.Environ(), "DEBIAN_FRONTEND=noninteractive")
	if out, err := updateCmd.CombinedOutput(); err != nil {
		respond(w, http.StatusUnprocessableEntity, map[string]string{
			"status": "error",
			"output": "apt-get update failed: " + string(out),
		})
		return
	}

	cmd := exec.Command("apt-get", "upgrade", "-y",
		"-o", "Dpkg::Options::=--force-confold",
		"-o", "Dpkg::Options::=--force-confdef",
		"--with-new-pkgs",
	)
	cmd.Env = append(cmd.Environ(), "DEBIAN_FRONTEND=noninteractive")

	type result struct {
		output []byte
		err    error
	}
	ch := make(chan result, 1)

	go func() {
		out, err := cmd.CombinedOutput()
		ch <- result{out, err}
	}()

	select {
	case res := <-ch:
		if res.err != nil {
			respond(w, http.StatusUnprocessableEntity, map[string]string{
				"status": "error",
				"output": string(res.output),
			})
			return
		}
		respond(w, http.StatusOK, map[string]string{
			"status": "upgraded",
			"output": string(res.output),
		})

	case <-time.After(10 * time.Minute):
		if cmd.Process != nil {
			cmd.Process.Kill()
		}
		http.Error(w, "upgrade timed out", http.StatusGatewayTimeout)
	}
}
