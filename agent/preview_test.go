package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestPreviewRequiresCurrentEmployeeAndCookie(t *testing.T) {
	a := &Agent{session: &Session{User: User{ID: "employee-a"}, ExpiresAt: time.Now().Add(time.Hour)}, preview: &ScreenshotPreview{ID: "shot", UserID: "employee-a", PNG: []byte("image")}}
	handler := agentHandler(a, "http://127.0.0.1:8765", "secret")
	for _, test := range []struct {
		name, cookie, owner, id string
		expected                int
	}{
		{"no cookie", "", "employee-a", "shot", 401},
		{"wrong cookie", "wrong", "employee-a", "shot", 401},
		{"other employee", "secret", "employee-b", "shot", 404},
		{"stale image", "secret", "employee-a", "old", 404},
		{"own image", "secret", "employee-a", "shot", 200},
	} {
		t.Run(test.name, func(t *testing.T) {
			a.preview.UserID = test.owner
			r := httptest.NewRequest("GET", "http://127.0.0.1:8765/local/screenshot-preview?id="+test.id, nil)
			if test.cookie != "" {
				r.AddCookie(&http.Cookie{Name: "activity_agent", Value: test.cookie})
			}
			w := httptest.NewRecorder()
			handler.ServeHTTP(w, r)
			if w.Code != test.expected {
				t.Fatalf("got %d, want %d", w.Code, test.expected)
			}
			if w.Header().Get("Cache-Control") != "no-store" {
				t.Fatal("preview may be cached")
			}
		})
	}
	a.session.ExpiresAt = time.Now().Add(-time.Second)
	w := httptest.NewRecorder()
	a.servePreview(w, httptest.NewRequest("GET", "/local/screenshot-preview?id=shot", nil))
	if w.Code != 401 {
		t.Fatal("expired session can read image")
	}
}
