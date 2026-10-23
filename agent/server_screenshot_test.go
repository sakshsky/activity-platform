package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestServerScreenshotProxyRechecksSessionAndDoesNotExposeToken(t *testing.T) {
	const id = "12345678-1234-4234-8234-123456789abc"
	a := &Agent{}
	expire := false
	calls := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if r.Header.Get("Authorization") != "Bearer secret" || r.URL.Path != "/api/agent/my-screenshots/"+id {
			t.Error("invalid authenticated image request")
		}
		if expire {
			a.Lock()
			a.session = nil
			a.Unlock()
		}
		w.Header().Set("Content-Type", "image/png")
		w.Write([]byte("png-bytes"))
	}))
	defer server.Close()
	a.client = newClient(server.URL)
	a.session = &Session{Token: "secret", ExpiresAt: time.Now().Add(time.Hour)}
	handler := agentHandler(a, "http://127.0.0.1:8765", "cookie")
	request := func(cookie bool, imageID string) *httptest.ResponseRecorder {
		r := httptest.NewRequest("GET", "http://127.0.0.1:8765/local/my-screenshot?id="+imageID, nil)
		if cookie {
			r.AddCookie(&http.Cookie{Name: "activity_agent", Value: "cookie"})
		}
		w := httptest.NewRecorder()
		handler.ServeHTTP(w, r)
		return w
	}
	if request(false, id).Code != 401 || calls != 0 {
		t.Fatal("unauthorized image fetch")
	}
	if request(true, "invalid").Code != 400 || calls != 0 {
		t.Fatal("invalid image ID accepted")
	}
	result := request(true, id)
	if result.Code != 200 || result.Body.String() != "png-bytes" || result.Header().Get("Cache-Control") != "no-store" {
		t.Fatal("invalid image proxy response")
	}
	expire = true
	if request(true, id).Code != 401 {
		t.Fatal("image exposed after session changed")
	}
}
