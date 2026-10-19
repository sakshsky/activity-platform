package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func queuedEvent() Job {
	return Job{Event: Event{ID: uuid(), CapturedAt: time.Now().UTC(), App: "synthetic.exe"}, Kind: "activity", UserID: "employee-one"}
}
func TestQueueSurvivesRestartAndAcknowledgement(t *testing.T) {
	root := t.TempDir()
	q, err := openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	job := queuedEvent()
	if err := q.add(job, 50); err != nil {
		t.Fatal(err)
	}
	if err := q.retry(job.ID); err != nil {
		t.Fatal(err)
	}
	loaded, err := openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	if jobs := loaded.ready(job.UserID, time.Now(), 10); len(jobs) != 0 {
		t.Fatal("retry delay was not retained")
	}
	jobs := loaded.ready(job.UserID, time.Now().Add(time.Hour), 10)
	if len(jobs) != 1 || jobs[0].ID != job.ID || jobs[0].Attempts != 1 {
		t.Fatal("queued job changed across restart")
	}
	if len(loaded.ready("another-user", time.Now().Add(time.Hour), 10)) != 0 {
		t.Fatal("another employee received queued data")
	}
	if err := loaded.ack(job.ID); err != nil {
		t.Fatal(err)
	}
	loaded, err = openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	count, _ := loaded.stats()
	if count != 0 {
		t.Fatal("acknowledged job reappeared")
	}
}
func TestQueueLimitsAndRetention(t *testing.T) {
	q, err := openQueue(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	large := queuedEvent()
	large.Bytes = 51 * 1024 * 1024
	if q.add(large, 50) != ErrQueueFull {
		t.Fatal("queue storage bound not enforced")
	}
	old := queuedEvent()
	old.CapturedAt = time.Now().Add(-8 * 24 * time.Hour)
	q.add(old, 50)
	recent := queuedEvent()
	q.add(recent, 50)
	if q.prune(7) != 1 {
		t.Fatal("expired job not pruned")
	}
	if count, _ := q.stats(); count != 1 {
		t.Fatal("recent job not retained")
	}
}
func TestDeviceIdentifierPersists(t *testing.T) {
	root := t.TempDir()
	first, err := loadIdentity(root)
	if err != nil || !validID(first) {
		t.Fatal("invalid device ID")
	}
	second, err := loadIdentity(root)
	if err != nil || second != first {
		t.Fatal("device identity changed on restart")
	}
}
func TestUnsafeQueuePathIsRejected(t *testing.T) {
	root := t.TempDir()
	q, err := openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	job := queuedEvent()
	job.Kind = "screenshot"
	job.File = "../../outside.png"
	if err := writeJSON(filepath.Join(q.root, "outbox", job.ID+".json"), job); err != nil {
		t.Fatal(err)
	}
	loaded, err := openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	if count, _ := loaded.stats(); count != 0 {
		t.Fatal("unsafe payload entered live queue")
	}
	if _, err := os.Stat(filepath.Join(root, "quarantine", job.ID+".json")); err != nil {
		t.Fatal("bad manifest was not quarantined")
	}
}

func TestAtomicWriteReplacesExistingFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "session.bin")
	for _, value := range []string{"first", "replacement", "third"} {
		if err := atomicWrite(path, []byte(value)); err != nil {
			t.Fatal(err)
		}
		got, err := os.ReadFile(path)
		if err != nil || string(got) != value {
			t.Fatal("replacement not persisted")
		}
	}
}

func TestMissingPayloadDoesNotBlockHealthyQueue(t *testing.T) {
	root := t.TempDir()
	q, _ := openQueue(root)
	healthy := queuedEvent()
	if err := q.add(healthy, 50); err != nil {
		t.Fatal(err)
	}
	broken := queuedEvent()
	broken.Kind = "screenshot"
	broken.File = broken.ID + ".png"
	if err := q.add(broken, 50); err != nil {
		t.Fatal(err)
	}
	loaded, err := openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	jobs := loaded.ready(healthy.UserID, time.Now(), 10)
	if len(jobs) != 1 || jobs[0].ID != healthy.ID {
		t.Fatal("healthy job was blocked")
	}
}

func TestRejectedAndExhaustedUploadsSurviveWithoutRetry(t *testing.T) {
	root := t.TempDir()
	a, err := newAgent(Config{ServerURL: "http://127.0.0.1:8000"}, root)
	if err != nil {
		t.Fatal(err)
	}
	job := queuedEvent()
	if err := a.queue.add(job, 50); err != nil {
		t.Fatal(err)
	}
	a.finishJob(job, &APIError{Code: 422, Message: "invalid dimensions"})
	exhausted := queuedEvent()
	exhausted.Attempts = 99
	if err := a.queue.add(exhausted, 50); err != nil {
		t.Fatal(err)
	}
	if err := a.queue.retry(exhausted.ID); err != nil {
		t.Fatal(err)
	}
	loaded, err := openQueue(root)
	if err != nil {
		t.Fatal(err)
	}
	if len(loaded.ready(job.UserID, time.Now().Add(24*time.Hour), 10)) != 0 || loaded.failedCount() != 2 {
		t.Fatal("failed uploads retried after restart")
	}
	if count, _ := loaded.stats(); count != 2 {
		t.Fatal("failed records unexpectedly deleted")
	}
}

func TestCaptureWindowsSkipSleepWithoutReplaying(t *testing.T) {
	start := time.Unix(0, 0)
	period := 120 * time.Second
	for _, test := range []struct{ elapsed, want time.Duration }{{0, 0}, {119 * time.Second, 0}, {120 * time.Second, 120 * time.Second}, {719 * time.Second, 600 * time.Second}, {720 * time.Second, 720 * time.Second}} {
		got := currentCaptureWindow(start, start.Add(test.elapsed), period)
		if !got.Equal(start.Add(test.want)) {
			t.Fatalf("elapsed %v: %v", test.elapsed, got)
		}
	}
	if !currentCaptureWindow(start.Add(period), start.Add(30*time.Second), period).Equal(start.Add(period)) {
		t.Fatal("replayed consumed window")
	}
}

func TestExplicitIntranetURLsAreAccepted(t *testing.T) {
	if got := cleanBrowserAddress("http://intranet/work?token=secret#tab"); got != "http://intranet/work" {
		t.Fatal(got)
	}
	if cleanBrowserAddress("searchterm") != "" {
		t.Fatal("search term became URL")
	}
}
func TestScreenshotRequiresServerAcknowledgement(t *testing.T) {
	root := t.TempDir()
	q, _ := openQueue(root)
	job := queuedEvent()
	job.Kind = "screenshot"
	job.File = job.ID + ".png"
	os.WriteFile(filepath.Join(root, "payloads", job.File), []byte("synthetic PNG bytes"), 0600)
	q.add(job, 50)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer device-token" {
			t.Error("missing device token")
		}
		if err := r.ParseMultipartForm(1024); err != nil {
			t.Error(err)
		}
		if r.FormValue("event_id") != job.ID {
			t.Error("event ID changed")
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"accepted":false}`))
	}))
	defer server.Close()
	if err := newClient(server.URL).upload(context.Background(), "device-token", root, job); err == nil {
		t.Fatal("unconfirmed screenshot marked successful")
	}
	if count, _ := q.stats(); count != 1 {
		t.Fatal("unconfirmed screenshot was removed")
	}
}
func TestRevokedDeviceStopsMonitoringAndRetainsQueue(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(403)
		json.NewEncoder(w).Encode(map[string]string{"message": "Device disabled"})
	}))
	defer server.Close()
	a, err := newAgent(Config{ServerURL: server.URL, DeviceName: "Synthetic test"}, t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	session := Session{Token: "test", User: User{ID: "employee-one"}, ExpiresAt: time.Now().Add(time.Hour), Policy: defaultPolicy()}
	a.useSession(session)
	job := queuedEvent()
	a.queue.add(job, 50)
	a.refresh(context.Background())
	if a.sessionCopy() != nil {
		t.Fatal("revoked session retained")
	}
	if a.tracking {
		t.Fatal("revoked device kept tracking")
	}
	if count, _ := a.queue.stats(); count != 1 {
		t.Fatal("revocation unexpectedly deleted queued data")
	}
}
func TestURLAndServerBoundaries(t *testing.T) {
	if secureServer("http://example.com") || secureServer("https://user:secret@example.com") || !secureServer("https://example.com") || !secureServer("http://127.0.0.1:8000") {
		t.Fatal("server transport validation failed")
	}
	if got := cleanBrowserAddress("https://user:secret@example.com/work?token=secret#anchor"); got != "https://example.com/work" {
		t.Fatalf("URL leaked sensitive parts: %s", got)
	}
}
