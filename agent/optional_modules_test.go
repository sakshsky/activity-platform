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

func TestTimingRuleRequiresLongRepeatedIntervals(t *testing.T) {
	start := time.Now()
	regular := make([]uint32, 120)
	for i := range regular {
		regular[i] = 5000
	}
	if timingEvidence(start, start.Add(10*time.Minute), regular) == nil {
		t.Fatal("regular evidence missing")
	}
	if timingEvidence(start, start.Add(time.Minute), regular) != nil {
		t.Fatal("short window flagged")
	}
	for i := range regular {
		regular[i] = uint32(1100 + i*271)
	}
	if timingEvidence(start, start.Add(10*time.Minute), regular) != nil {
		t.Fatal("irregular input flagged")
	}
	for i := range regular {
		regular[i] = 1000
	}
	if timingEvidence(start, start.Add(10*time.Minute), regular) != nil {
		t.Fatal("frequent ordinary input flagged")
	}
}
func TestOptionalPayloadsSurviveQueueRestart(t *testing.T) {
	root := t.TempDir()
	q, e := openQueue(root)
	if e != nil {
		t.Fatal(e)
	}
	id := uuid()
	os.WriteFile(filepath.Join(root, "payloads", id+".mp4"), []byte("video"), 0600)
	q.add(Job{Event: Event{ID: id, CapturedAt: time.Now()}, Kind: "video", File: id + ".mp4", UserID: "owner", Bytes: 5, NoticeVersion: 2}, 50)
	q.add(Job{Event: Event{ID: uuid(), CapturedAt: time.Now()}, Kind: "anomaly", UserID: "owner", NoticeVersion: 2, Anomaly: &AnomalyEvidence{PeriodMS: 5000}}, 50)
	loaded, e := openQueue(root)
	if e != nil {
		t.Fatal(e)
	}
	if jobs := loaded.ready("owner", time.Now().Add(time.Hour), 10); len(jobs) != 2 {
		t.Fatal(len(jobs))
	}
}
func TestVideoMultipartAndProxyRange(t *testing.T) {
	root := t.TempDir()
	os.Mkdir(filepath.Join(root, "payloads"), 0700)
	id := uuid()
	os.WriteFile(filepath.Join(root, "payloads", id+".mp4"), []byte("video"), 0600)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer secret" {
			t.Error("token missing")
		}
		if r.Method == "POST" {
			r.ParseMultipartForm(1024)
			defer r.MultipartForm.RemoveAll()
			if r.FormValue("notice_version") != "2" || r.URL.Path != "/api/agent/videos" {
				t.Error("wrong video upload")
			}
			w.Write([]byte(`{"accepted":true}`))
			return
		}
		if r.Header.Get("Range") != "bytes=0-3" {
			t.Error("range missing")
		}
		w.Header().Set("Content-Type", "video/mp4")
		w.Header().Set("Content-Range", "bytes 0-3/5")
		w.WriteHeader(206)
		w.Write([]byte("vide"))
	}))
	defer server.Close()
	c := newClient(server.URL)
	if e := c.upload(context.Background(), "secret", root, Job{Event: Event{ID: id, CapturedAt: time.Now()}, Kind: "video", File: id + ".mp4", NoticeVersion: 2}); e != nil {
		t.Fatal(e)
	}
	a := &Agent{client: c, session: &Session{Token: "secret", ExpiresAt: time.Now().Add(time.Hour)}}
	r := httptest.NewRequest("GET", "/local/my-video?id="+id, nil)
	r.Header.Set("Range", "bytes=0-3")
	w := httptest.NewRecorder()
	a.serverScreenshot(w, r)
	if w.Code != 206 || w.Body.String() != "vide" || w.Header().Get("Content-Range") == "" {
		t.Fatal("range response lost")
	}
}
func TestVideoArgsAreSilentBoundedAndNoShell(t *testing.T) {
	args := strings.Join(videoArguments(10, "clip.mp4"), " ")
	for _, required := range []string{"-an", "-framerate 2", "-t 10", "-fs 20971520", "1280:720"} {
		if !strings.Contains(args, required) {
			t.Fatal(required)
		}
	}
}
