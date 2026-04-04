package api

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"strconv"
	"time"

	"github.com/creack/pty"
	"github.com/gorilla/websocket"
)

var wsUpgrader = websocket.Upgrader{
	HandshakeTimeout: 10 * time.Second,
	CheckOrigin: func(r *http.Request) bool {
		// Origin check is deferred to token validation
		return true
	},
}

// tokenTTL is how long a shell token remains valid.
const tokenTTL = 60 * time.Second

// ShellTokenSign returns the HMAC-SHA256 hex signature for a shell token.
// Signed payload: "shell:{ts}"
func ShellTokenSign(secret string, ts int64) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(fmt.Sprintf("shell:%d", ts)))
	return hex.EncodeToString(mac.Sum(nil))
}

// HandleShell returns an http.HandlerFunc for the WebSocket shell endpoint.
// Auth: ?ts=<unix>&sig=<hex-hmac>
func HandleShell(secret string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		// ── Validate token ────────────────────────────────────────────────
		tsStr := r.URL.Query().Get("ts")
		sig := r.URL.Query().Get("sig")

		if tsStr == "" || sig == "" {
			http.Error(w, "missing token", http.StatusUnauthorized)
			return
		}

		ts, err := strconv.ParseInt(tsStr, 10, 64)
		if err != nil {
			http.Error(w, "invalid ts", http.StatusUnauthorized)
			return
		}

		age := time.Since(time.Unix(ts, 0))
		if age < 0 {
			age = -age
		}
		if age > tokenTTL {
			http.Error(w, "token expired", http.StatusUnauthorized)
			return
		}

		expected := ShellTokenSign(secret, ts)
		if !hmac.Equal([]byte(expected), []byte(sig)) {
			http.Error(w, "invalid token", http.StatusUnauthorized)
			return
		}

		// ── Upgrade to WebSocket ──────────────────────────────────────────
		conn, err := wsUpgrader.Upgrade(w, r, nil)
		if err != nil {
			log.Printf("shell: ws upgrade: %v", err)
			return
		}
		defer conn.Close()

		// ── Spawn PTY ─────────────────────────────────────────────────────
		shell := "/bin/bash"
		if _, err := os.Stat(shell); os.IsNotExist(err) {
			shell = "/bin/sh"
		}

		cmd := exec.Command(shell)
		cmd.Env = append(os.Environ(),
			"TERM=xterm-256color",
			"HOME=/root",
		)

		ptmx, err := pty.Start(cmd)
		if err != nil {
			log.Printf("shell: pty start: %v", err)
			conn.WriteMessage(websocket.TextMessage, []byte("\r\nFailed to start shell: "+err.Error()+"\r\n"))
			return
		}
		defer func() {
			ptmx.Close()
			cmd.Process.Kill()
			cmd.Wait()
		}()

		// ── PTY → WebSocket ───────────────────────────────────────────────
		go func() {
			buf := make([]byte, 4096)
			for {
				n, err := ptmx.Read(buf)
				if n > 0 {
					if werr := conn.WriteMessage(websocket.BinaryMessage, buf[:n]); werr != nil {
						return
					}
				}
				if err != nil {
					if err != io.EOF {
						log.Printf("shell: pty read: %v", err)
					}
					conn.WriteMessage(websocket.TextMessage, []byte("\r\n[session ended]\r\n"))
					conn.Close()
					return
				}
			}
		}()

		// ── WebSocket → PTY ───────────────────────────────────────────────
		for {
			msgType, data, err := conn.ReadMessage()
			if err != nil {
				return
			}

			if msgType == websocket.TextMessage {
				// Control message: {"type":"resize","cols":80,"rows":24}
				var ctrl struct {
					Type string `json:"type"`
					Cols uint16 `json:"cols"`
					Rows uint16 `json:"rows"`
				}
				if jerr := json.Unmarshal(data, &ctrl); jerr == nil && ctrl.Type == "resize" {
					pty.Setsize(ptmx, &pty.Winsize{
						Cols: ctrl.Cols,
						Rows: ctrl.Rows,
					})
				}
				continue
			}

			// Binary message = stdin data
			if _, err := ptmx.Write(data); err != nil {
				return
			}
		}
	}
}
