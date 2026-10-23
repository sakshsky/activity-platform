package main

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestScreenshotDeletionProxySecurity(t *testing.T) {
	calls := 0
	id := "55555555-5555-4555-8555-555555555555"
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if r.URL.Path != "/api/agent/my-screenshots/"+id+"/delete" || r.Header.Get("Authorization") != "Bearer private-token" {
			t.Error("incorrect upstream request")
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(202)
		w.Write([]byte(`{"deleted":true,"cleanup_pending":true}`))
	}))
	defer server.Close()
	a := &Agent{client: newClient(server.URL), session: &Session{Token: "private-token", ExpiresAt: time.Now().Add(time.Hour)}}
	h := agentHandler(a, "http://127.0.0.1:8765", "cookie-secret")
	for _, tc := range []struct {
		cookie     bool
		origin, id string
		want       int
	}{{false, "http://127.0.0.1:8765", id, 401}, {true, "https://evil.test", id, 403}, {true, "http://127.0.0.1:8765", "../wrong", 400}, {true, "http://127.0.0.1:8765", id, 200}} {
		r := httptest.NewRequest("POST", "http://127.0.0.1:8765/local/my-screenshot/delete", strings.NewReader(`{"id":"`+tc.id+`","reason":"Personal screen"}`))
		r.Header.Set("Origin", tc.origin)
		r.Header.Set("Content-Type", "application/json")
		if tc.cookie {
			r.AddCookie(&http.Cookie{Name: "activity_agent", Value: "cookie-secret"})
		}
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		if w.Code != tc.want {
			t.Fatalf("got %d want %d: %s", w.Code, tc.want, w.Body.String())
		}
		if strings.Contains(w.Body.String(), "private-token") {
			t.Fatal("token exposed")
		}
	}
	if calls != 1 {
		t.Fatalf("unexpected upstream count %d", calls)
	}
}
