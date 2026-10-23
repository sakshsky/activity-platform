package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestScreenshotMetadataSurvivesQueueAndMultipartUpload(t *testing.T) {
	root := t.TempDir()
	queue, err := openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	idle := uint32(12)
	job := Job{Event: Event{ID: uuid(), CapturedAt: time.Now().UTC(), App: "editor.exe"}, Kind: "screenshot", UserID: "owner", File: "capture.png", CaptureState: "active", IdleSeconds: &idle, AgentVersion: "1.2.0", Bytes: 7}
	job.File = job.ID + ".png"
	if err := os.WriteFile(filepath.Join(root, "payloads", job.File), []byte("fixture"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := queue.add(job, 50); err != nil {
		t.Fatal(err)
	}
	loaded, err := openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	jobs := loaded.ready("owner", time.Now().Add(time.Hour), 1)
	if len(jobs) != 1 || jobs[0].IdleSeconds == nil || *jobs[0].IdleSeconds != 12 {
		t.Fatal("capture metadata lost on restart")
	}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := r.ParseMultipartForm(1024 * 1024); err != nil {
			t.Error(err)
			w.WriteHeader(400)
			return
		}
		defer r.MultipartForm.RemoveAll()
		for key, value := range map[string]string{"capture_state": "active", "idle_seconds": "12", "idle_threshold_seconds": "300", "agent_version": "1.2.0", "app_name": "editor.exe"} {
			if r.FormValue(key) != value {
				t.Errorf("%s missing or changed", key)
			}
		}
		if r.FormValue("employee_id") != "" {
			t.Error("client must not assign employee identity")
		}
		if r.Header.Get("Authorization") != "Bearer secret" {
			t.Error("missing authentication")
		}
		w.Write([]byte(`{"accepted":true}`))
	}))
	defer server.Close()
	if err := newClient(server.URL).upload(context.Background(), "secret", root, jobs[0]); err != nil {
		t.Fatal(err)
	}
}

func TestPerformanceProxyRestrictsIdentityAndWriteOrigin(t *testing.T) {
	calls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if r.Header.Get("Authorization") != "Bearer hidden-token" {
			t.Error("missing authentication")
		}
		if r.URL.Path == "/api/agent/performance" {
			if r.URL.Query().Get("week") != "2026-10-05" || r.URL.Query().Has("user_id") {
				t.Error("unsafe report query")
			}
		}
		w.Write([]byte(`{"success":true}`))
	}))
	defer server.Close()
	a := &Agent{client: newClient(server.URL), session: &Session{Token: "hidden-token", User: User{ID: "owner"}, ExpiresAt: time.Now().Add(time.Hour)}}
	handler := agentHandler(a, "http://127.0.0.1:8765", "cookie")
	for _, tc := range []struct {
		method, path, body, origin string
		code                       int
	}{
		{"GET", "/local/performance?week=2026-10-05&user_id=other", "", "", 200},
		{"POST", "/local/task-submit", `{"id":"bad"}`, "http://127.0.0.1:8765", 400},
		{"POST", "/local/work-context", `{"note":"meeting"}`, "https://other.example", 403},
		{"POST", "/local/task-submit", `{"id":"` + uuid() + `","revision":0,"evidence":"Completed"}`, "http://127.0.0.1:8765", 200},
	} {
		req := httptest.NewRequest(tc.method, "http://127.0.0.1:8765"+tc.path, strings.NewReader(tc.body))
		req.AddCookie(&http.Cookie{Name: "activity_agent", Value: "cookie"})
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Origin", tc.origin)
		res := httptest.NewRecorder()
		handler.ServeHTTP(res, req)
		if res.Code != tc.code {
			t.Fatalf("%s returned %d: %s", tc.path, res.Code, res.Body.String())
		}
		if strings.Contains(res.Body.String(), "hidden-token") {
			t.Fatal("token leaked")
		}
	}
	if calls != 2 {
		t.Fatalf("unexpected server calls: %d", calls)
	}
}
