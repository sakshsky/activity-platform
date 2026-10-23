package main

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"time"
)

// Forward only explicitly supported employee operations. Never expose the token to JavaScript.
func (a *Agent) portal(w http.ResponseWriter, r *http.Request) {
	a.Lock()
	client := a.client
	var session *Session
	if a.session != nil {
		copy := *a.session
		session = &copy
	}
	a.Unlock()
	if session == nil || !session.ExpiresAt.After(time.Now()) {
		http.Error(w, "Sign in again", 401)
		return
	}
	path := "/api/agent/portal"
	var input map[string]any
	if r.Method == "POST" {
		limit := int64(8192)
		if r.URL.Path == "/local/profile" {
			limit = 65536
		}
		if json.NewDecoder(http.MaxBytesReader(w, r.Body, limit)).Decode(&input) != nil {
			http.Error(w, "Invalid request", 400)
			return
		}
		switch r.URL.Path {
		case "/local/my-screenshot/delete":
			id, _ := input["id"].(string)
			if !validID(id) {
				http.Error(w, "Invalid screenshot identifier", 400)
				return
			}
			path = "/api/agent/my-screenshots/" + id + "/delete"
		case "/local/modules-consent":
			path = "/api/agent/modules/consent"
		case "/local/anomaly-context":
			id, _ := input["id"].(string)
			if !validID(id) {
				http.Error(w, "Invalid review identifier", 400)
				return
			}
			path = "/api/agent/anomalies/" + id + "/context"
		case "/local/task-submit":
			id, _ := input["id"].(string)
			if !validID(id) {
				http.Error(w, "Invalid task identifier", 400)
				return
			}
			path = "/api/agent/tasks/" + id + "/submit"
		case "/local/work-context":
			path = "/api/agent/work-context"
		case "/local/profile":
			path = "/api/agent/profile"
		case "/local/leave":
			path = "/api/agent/leave"
		case "/local/leave-cancel":
			id, _ := input["id"].(string)
			if !validID(id) {
				http.Error(w, "Invalid request identifier", 400)
				return
			}
			path = "/api/agent/leave/" + id + "/cancel"
		default:
			http.NotFound(w, r)
			return
		}
	} else {
		if r.URL.Path == "/local/videos" {
			path = "/api/agent/videos"
		}
		if r.URL.Path == "/local/anomalies" {
			path = "/api/agent/anomalies"
		}
		if r.URL.Path == "/local/performance" {
			path = "/api/agent/performance"
		}
		if r.URL.Path == "/local/my-data" {
			path = "/api/agent/my-data"
		}
		query := url.Values{}
		for _, key := range []string{"timezone", "page", "kind", "from", "to", "week"} {
			if value := r.URL.Query().Get(key); value != "" {
				query.Set(key, value)
			}
		}
		path += "?" + query.Encode()
	}
	ctx, cancel := context.WithTimeout(r.Context(), 20*time.Second)
	defer cancel()
	var result json.RawMessage
	var body any
	if input != nil {
		body = input
	}
	err := client.request(ctx, path, session.Token, body, &result)
	current := a.sessionCopy()
	if current == nil || current.Token != session.Token || !current.ExpiresAt.After(time.Now()) {
		http.Error(w, "Session changed; reload", 401)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	if err != nil {
		code := http.StatusBadGateway
		message := err.Error()
		var apiErr *APIError
		if errors.As(err, &apiErr) {
			code = apiErr.Code
		}
		if code == 404 {
			message = "Update the Laravel server and run its migrations to enable the employee portal."
		}
		w.WriteHeader(code)
		json.NewEncoder(w).Encode(map[string]string{"message": message})
		return
	}
	w.Write(result)
}
