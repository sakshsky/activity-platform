package main

import (
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// Keep just the latest attempted upload in memory, never a second disk archive.
type ScreenshotPreview struct {
	ID, UserID, State string
	CapturedAt        time.Time
	PNG               []byte
}

func (p *ScreenshotPreview) metadata() map[string]any {
	return map[string]any{"id": p.ID, "captured_at": p.CapturedAt, "state": p.State}
}

func (a *Agent) showUploadPreview(session Session, job Job) {
	info, err := os.Stat(filepath.Join(a.root, "payloads", job.File))
	if err != nil || info.Size() > 20*1024*1024 {
		return
	}
	data, err := os.ReadFile(filepath.Join(a.root, "payloads", job.File))
	if err != nil {
		return
	}
	a.Lock()
	defer a.Unlock()
	if a.session == nil || a.session.Token != session.Token || !session.ExpiresAt.After(time.Now()) {
		return
	}
	a.preview = &ScreenshotPreview{ID: job.ID, UserID: session.User.ID, State: "Uploading…", CapturedAt: job.CapturedAt, PNG: data}
}

func (a *Agent) servePreview(w http.ResponseWriter, r *http.Request) {
	a.Lock()
	if a.session == nil || !a.session.ExpiresAt.After(time.Now()) {
		a.Unlock()
		http.Error(w, "Login required", http.StatusUnauthorized)
		return
	}
	if a.preview == nil || a.preview.UserID != a.session.User.ID || r.URL.Query().Get("id") != a.preview.ID {
		a.Unlock()
		http.NotFound(w, r)
		return
	}
	data := a.preview.PNG // Immutable bytes remain valid after the preview is replaced.
	a.Unlock()
	w.Header().Set("Content-Type", "image/png")
	w.Write(data)
}
