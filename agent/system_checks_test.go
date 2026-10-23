package main

import (
	"net/http/httptest"
	"testing"
)

func TestSystemChecksRequireBrowserCookieAndOrigin(t *testing.T) {
	h := agentHandler(&Agent{}, "http://127.0.0.1:8765", "secret")
	r := httptest.NewRequest("POST", "http://127.0.0.1:8765/local/system-check", nil)
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 401 {
		t.Fatal(w.Code)
	}
	r.Header.Set("Cookie", "activity_agent=secret")
	w = httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 403 {
		t.Fatal(w.Code)
	}
}
