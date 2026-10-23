package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestBrowserBootstrapBeforeLoginAndAfterLogout(t *testing.T) {
	a, err := newAgent(Config{}, t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	origin := "http://127.0.0.1:8765"
	handler := agentHandler(a, origin, "browser-secret")
	page := httptest.NewRecorder()
	handler.ServeHTTP(page, httptest.NewRequest("GET", origin+"/agent", nil))
	if page.Code != 200 {
		t.Fatal(page.Code)
	}
	cookies := page.Result().Cookies()
	if len(cookies) != 1 || !cookies[0].HttpOnly || cookies[0].SameSite != http.SameSiteStrictMode {
		t.Fatal("missing protected browser cookie")
	}
	cookie := cookies[0]
	request := func(method, path string, authenticated bool) *httptest.ResponseRecorder {
		r := httptest.NewRequest(method, origin+path, nil)
		if authenticated {
			r.AddCookie(cookie)
		}
		if method == "POST" {
			r.Header.Set("Origin", origin)
			r.Header.Set("Content-Type", "application/json")
		}
		w := httptest.NewRecorder()
		handler.ServeHTTP(w, r)
		return w
	}
	if w := request("GET", "/local/status", true); w.Code != 200 {
		t.Fatalf("first-run status: %d", w.Code)
	}
	if w := request("GET", "/local/portal", true); w.Code != 401 {
		t.Fatal("browser cookie granted employee access")
	}
	if w := request("GET", "/local/status", false); w.Code != 401 {
		t.Fatal("status allowed without browser cookie")
	}
	logout := request("POST", "/local/logout", true)
	if logout.Code != 200 {
		t.Fatal(logout.Code)
	}
	cookies = logout.Result().Cookies()
	if len(cookies) != 1 || cookies[0].MaxAge < 0 {
		t.Fatal("sign-out removed browser access")
	}
	cookie = cookies[0]
	if w := request("GET", "/local/status", true); w.Code != 200 {
		t.Fatalf("signed-out status: %d", w.Code)
	}
	if w := request("GET", "/local/portal", true); w.Code != 401 {
		t.Fatal("signed-out portal allowed")
	}
	// Restart replaces the process-local cookie when the employee reopens the page.
	restarted := agentHandler(a, origin, "new-browser-secret")
	page = httptest.NewRecorder()
	restarted.ServeHTTP(page, httptest.NewRequest("GET", origin+"/agent", nil))
	if page.Result().Cookies()[0].Value != "new-browser-secret" {
		t.Fatal("restart did not renew cookie")
	}
}
