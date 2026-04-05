package api

import (
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"github.com/go-chi/chi/v5"
)

// POST /v1/backups/{username}/push
// Body: {filename, destination_type, host, port, remote_user, remote_path, ssh_private_key}
func handleBackupPush(w http.ResponseWriter, r *http.Request) {
	username := chi.URLParam(r, "username")
	var req struct {
		Filename      string `json:"filename"`
		DestType      string `json:"destination_type"` // sftp | s3
		Host          string `json:"host"`
		Port          string `json:"port"`
		RemoteUser    string `json:"remote_user"`
		RemotePath    string `json:"remote_path"`
		SSHPrivateKey string `json:"ssh_private_key"`
		S3Bucket      string `json:"s3_bucket"`
		S3KeyID       string `json:"s3_key_id"`
		S3KeySecret   string `json:"s3_key_secret"`
		S3Region      string `json:"s3_region"`
	}
	if !decodeJSON(w, r, &req) {
		return
	}

	backupDir := filepath.Join("/home", username, "backups")
	localPath := filepath.Join(backupDir, req.Filename)
	if _, err := os.Stat(localPath); err != nil {
		http.Error(w, "backup file not found", http.StatusNotFound)
		return
	}

	var pushErr error
	switch req.DestType {
	case "sftp":
		pushErr = pushSFTP(localPath, req.Host, req.Port, req.RemoteUser, req.RemotePath, req.SSHPrivateKey, req.Filename)
	case "s3":
		pushErr = pushS3(localPath, req.S3Bucket, req.S3KeyID, req.S3KeySecret, req.S3Region, req.Filename)
	default:
		http.Error(w, "unsupported destination_type", http.StatusBadRequest)
		return
	}

	if pushErr != nil {
		http.Error(w, "push failed: "+pushErr.Error(), http.StatusInternalServerError)
		return
	}

	respond(w, http.StatusOK, map[string]string{"status": "pushed", "filename": req.Filename})
}

func pushSFTP(localPath, host, port, user, remotePath, privateKey, filename string) error {
	if port == "" {
		port = "22"
	}

	// Write temp key file
	keyFile, err := os.CreateTemp("", "strata-backup-key-*")
	if err != nil {
		return err
	}
	defer os.Remove(keyFile.Name())
	if _, err := keyFile.WriteString(privateKey); err != nil {
		return err
	}
	keyFile.Close()
	if err := os.Chmod(keyFile.Name(), 0600); err != nil {
		return err
	}

	dest := fmt.Sprintf("%s@%s:%s/%s", user, host, strings.TrimRight(remotePath, "/"), filename)
	cmd := exec.Command("scp",
		"-P", port,
		"-i", keyFile.Name(),
		"-o", "StrictHostKeyChecking=no",
		"-o", "BatchMode=yes",
		localPath,
		dest,
	)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}

func pushS3(localPath, bucket, keyID, keySecret, region, filename string) error {
	if region == "" {
		region = "us-east-1"
	}
	s3URI := fmt.Sprintf("s3://%s/%s", strings.TrimRight(bucket, "/"), filename)
	cmd := exec.Command("aws", "s3", "cp", localPath, s3URI, "--region", region)
	cmd.Env = append(os.Environ(),
		"AWS_ACCESS_KEY_ID="+keyID,
		"AWS_SECRET_ACCESS_KEY="+keySecret,
	)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}
