package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestStopSessionCancelsInFlightUpload(t *testing.T) {
	started, release := make(chan struct{}), make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		close(started)
		<-release
	}))
	defer server.Close()
	defer close(release)
	root := t.TempDir()
	if err := os.MkdirAll(filepath.Join(root, "payloads"), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "payloads", "capture.png"), []byte("test image"), 0600); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	a := &Agent{root: root, client: newClient(server.URL), cancel: cancel, session: &Session{Token: "test"}, tracking: true}
	a.workers.Add(1)
	uploadResult := make(chan error, 1)
	go func() {
		defer a.workers.Done()
		uploadResult <- a.client.upload(ctx, "test", root, Job{File: "capture.png"})
	}()
	select {
	case <-started:
	case <-time.After(5 * time.Second):
		t.Fatal("upload did not start")
	}
	done := make(chan struct{})
	go func() { a.stopSession("Signed out"); close(done) }()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("stop waited for upload timeout instead of cancelling")
	}
	if err := <-uploadResult; err == nil {
		t.Fatal("cancelled upload reported success")
	}
	if a.sessionCopy() != nil {
		t.Fatal("session retained after sign-out")
	}
}

func TestPortalDoesNotBlockTransitionsAndRejectsReplacedSession(t *testing.T) {
	started, release := make(chan struct{}), make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		close(started)
		<-release
		w.Write([]byte(`{"private":"old employee"}`))
	}))
	defer server.Close()
	a := &Agent{client: newClient(server.URL), session: &Session{Token: "old", ExpiresAt: time.Now().Add(time.Hour)}}
	response := httptest.NewRecorder()
	done := make(chan struct{})
	go func() { defer close(done); a.portal(response, httptest.NewRequest("GET", "/local/portal", nil)) }()
	<-started
	if !a.transition.TryLock() {
		close(release)
		<-done
		t.Fatal("Portal blocked transition mutex")
	}
	a.transition.Unlock()
	a.Lock()
	a.session = &Session{Token: "replacement", ExpiresAt: time.Now().Add(time.Hour)}
	a.Unlock()
	close(release)
	<-done
	if response.Code != 401 {
		t.Fatalf("Old session response escaped: %d", response.Code)
	}
}

type blockingPreviewWriter struct {
	header           http.Header
	started, release chan struct{}
}

func (w *blockingPreviewWriter) Header() http.Header { return w.header }
func (w *blockingPreviewWriter) WriteHeader(int)     {}
func (w *blockingPreviewWriter) Write(p []byte) (int, error) {
	close(w.started)
	<-w.release
	return len(p), nil
}
func TestPreviewWriteDoesNotHoldAgentMutex(t *testing.T) {
	a := &Agent{session: &Session{User: User{ID: "owner"}, ExpiresAt: time.Now().Add(time.Hour)}, preview: &ScreenshotPreview{ID: "image", UserID: "owner", PNG: []byte("image")}}
	w := &blockingPreviewWriter{header: http.Header{}, started: make(chan struct{}), release: make(chan struct{})}
	done := make(chan struct{})
	go func() {
		defer close(done)
		a.servePreview(w, httptest.NewRequest("GET", "/local/preview?id=image", nil))
	}()
	<-w.started
	if !a.TryLock() {
		close(w.release)
		<-done
		t.Fatal("Preview held agent mutex")
	}
	a.Unlock()
	close(w.release)
	<-done
}
func TestStatusRequiresCookie(t *testing.T) {
	a := &Agent{}
	w := httptest.NewRecorder()
	agentHandler(a, "http://127.0.0.1:8765", "secret").ServeHTTP(w, httptest.NewRequest("GET", "http://127.0.0.1:8765/local/status", nil))
	if w.Code != 401 {
		t.Fatal(w.Code)
	}
}
func TestPolicyIdleBounds(t *testing.T) {
	for _, value := range []int{0, 29, 601} {
		if (Policy{IdleThresholdSeconds: value}).bounded().IdleThresholdSeconds != 300 {
			t.Fatal(value)
		}
	}
	if (Policy{IdleThresholdSeconds: 120}).bounded().IdleThresholdSeconds != 120 {
		t.Fatal("valid threshold changed")
	}
}
