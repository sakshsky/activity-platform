package main

import (
	"context"
	"encoding/json"
	"net/http"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"
)

type systemCheck struct {
	Name   string `json:"name"`
	Status string `json:"status"`
	Detail string `json:"detail"`
}

func (a *Agent) systemChecks(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
	defer cancel()
	checks := []systemCheck{}
	add := func(name, status, detail string) { checks = append(checks, systemCheck{name, status, detail}) }
	a.Lock()
	server := a.config.ServerURL
	client := a.client
	policy := a.policy
	session := a.session != nil
	contact := a.lastContact
	a.Unlock()
	platform := "fail"
	if runtime.GOOS == "windows" && runtime.GOARCH == "amd64" {
		platform = "pass"
	}
	add("Windows desktop", platform, "Requires a signed-in Windows x64 desktop session for capture. Detected: "+runtime.GOOS+"/"+runtime.GOARCH)
	file, err := os.CreateTemp(a.root, "system-check-*.tmp")
	writable := false
	if err == nil {
		name := file.Name()
		_, err = file.WriteString("ok")
		closeErr := file.Close()
		data, readErr := os.ReadFile(name)
		removeErr := os.Remove(name)
		writable = err == nil && closeErr == nil && readErr == nil && removeErr == nil && string(data) == "ok"
	}
	status := "fail"
	if writable {
		status = "pass"
	}
	add("Local data read/write", status, "Tests a temporary file in the agent data folder. Keep the application in a writable folder.")
	binary, err := encoderPath()
	ready := err == nil
	if ready {
		for _, probe := range []struct{ arg, needle string }{{"-version", "ffmpeg version"}, {"-devices", "gdigrab"}, {"-encoders", "libx264"}} {
			cmd := exec.CommandContext(ctx, binary, "-hide_banner", probe.arg)
			hideCommand(cmd)
			out, e := cmd.CombinedOutput()
			if e != nil || !strings.Contains(string(out), probe.needle) {
				ready = false
				break
			}
		}
	}
	status = "warning"
	detail := "Place a Windows FFmpeg build with gdigrab and libx264 beside the agent. Required only for video."
	if ready {
		status = "pass"
		detail = "FFmpeg runs and advertises Windows desktop capture and H.264 encoding. No screen recording was made."
	}
	add("FFmpeg video support", status, detail)
	if server == "" {
		add("Server connection", "warning", "Save your company server address first.")
	} else {
		var info map[string]any
		err := client.request(ctx, "/api/agent/info", "", nil, &info)
		if err != nil {
			add("Server connection", "fail", err.Error()+" Check the server address, trusted certificate and network.")
		} else {
			add("Server connection", "pass", "The configured server answered its agent information endpoint. This does not verify uploads or employee authentication.")
		}
	}
	status = "warning"
	if session {
		status = "pass"
	}
	add("Employee session", status, "Sign in to collect data. System checks do not sign you in or change consent.")
	status = "warning"
	if policy.VideoEnabled && policy.ModulesConsented && session {
		status = "pass"
	}
	add("Video policy and acknowledgment", status, "Video requires an enabled server policy and employee acknowledgment of the current notice. Check Optional recording & activity review.")
	status = "warning"
	if !contact.IsZero() && time.Since(contact) < 60*time.Second {
		status = "pass"
	}
	add("Recent server contact", status, "Recording requires confirmed policy contact within the last 60 seconds.")
	add("Real capture and upload", "manual", "While signed in and active, confirm a clip appears in Videos. Idle or locked screens pause recording. Server FFprobe must also pass the administrator system check.")
	add("Capacity and build tools", "manual", "Allow disk space for the offline queue and configured retention. Go and Node are needed only to build from source, not to run this EXE.")
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"checked_at": time.Now().UTC(), "checks": checks})
}
