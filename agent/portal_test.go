package main

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestPortalProxyKeepsTokenOnServerAndRequiresCookie(t *testing.T) {
	calls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if r.Header.Get("Authorization") != "Bearer private-token" {
			t.Error("missing token")
		}
		if r.URL.Path != "/api/agent/portal" || r.URL.Query().Get("timezone") != "Asia/Kolkata" || r.URL.Query().Has("user_id") {
			t.Error("incorrect path or query forwarding")
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"daily":[]}`))
	}))
	defer server.Close()
	a := &Agent{client: newClient(server.URL), session: &Session{Token: "private-token", User: User{ID: "employee"}, ExpiresAt: time.Now().Add(time.Hour)}}
	handler := agentHandler(a, "http://127.0.0.1:8765", "cookie-secret")
	for _, authenticated := range []bool{false, true} {
		request := httptest.NewRequest("GET", "http://127.0.0.1:8765/local/portal?timezone=Asia%2FKolkata&user_id=other", nil)
		if authenticated {
			request.AddCookie(&http.Cookie{Name: "activity_agent", Value: "cookie-secret"})
		}
		response := httptest.NewRecorder()
		handler.ServeHTTP(response, request)
		expected := 401
		if authenticated {
			expected = 200
		}
		if response.Code != expected {
			t.Fatalf("got %d, want %d", response.Code, expected)
		}
		if strings.Contains(response.Body.String(), "private-token") {
			t.Fatal("token leaked to browser")
		}
	}
	if calls != 1 {
		t.Fatal("unauthorized request reached server")
	}
	request := httptest.NewRequest("POST", "http://127.0.0.1:8765/local/leave", strings.NewReader(`{}`))
	request.AddCookie(&http.Cookie{Name: "activity_agent", Value: "cookie-secret"})
	request.Header.Set("Origin", "https://untrusted.example")
	request.Header.Set("Content-Type", "application/json")
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, request)
	if response.Code != 403 || calls != 1 {
		t.Fatal("cross-origin write was forwarded")
	}
}
