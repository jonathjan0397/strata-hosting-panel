package api

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net/http"
	"strconv"
	"time"
)

const (
	headerSignature = "X-Strata-Signature"
	headerTimestamp = "X-Strata-Timestamp"
	headerUnsigned  = "X-Strata-Unsigned-Body"
	maxSkew         = 5 * time.Minute
)

// HMACAuth returns middleware that validates HMAC-SHA256 request signatures.
// The panel signs: HMAC-SHA256(secret, "timestamp\nbody")
// Header X-Strata-Timestamp: unix epoch seconds
// Header X-Strata-Signature: hex(hmac)
func HMACAuth(secret string) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			sig := r.Header.Get(headerSignature)
			tsStr := r.Header.Get(headerTimestamp)

			if sig == "" || tsStr == "" {
				http.Error(w, "missing auth headers", http.StatusUnauthorized)
				return
			}

			ts, err := strconv.ParseInt(tsStr, 10, 64)
			if err != nil {
				http.Error(w, "invalid timestamp", http.StatusUnauthorized)
				return
			}

			reqTime := time.Unix(ts, 0)
			skew := time.Since(reqTime)
			if skew < 0 {
				skew = -skew
			}
			if skew > maxSkew {
				http.Error(w, "timestamp out of range", http.StatusUnauthorized)
				return
			}

			mac := hmac.New(sha256.New, []byte(secret))
			mac.Write([]byte(tsStr))
			mac.Write([]byte("\n"))

			unsignedBody := r.Header.Get(headerUnsigned) == "1"
			if !unsignedBody {
				body, err := io.ReadAll(r.Body)
				if err != nil {
					http.Error(w, "read error", http.StatusBadRequest)
					return
				}
				mac.Write(body)
				r.Body = io.NopCloser(bytes.NewReader(body))
			}
			expected := hex.EncodeToString(mac.Sum(nil))

			if !hmac.Equal([]byte(expected), []byte(sig)) {
				http.Error(w, "invalid signature", http.StatusUnauthorized)
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}
