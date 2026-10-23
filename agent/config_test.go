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

func TestConfigureChecksServerAndPersistsWithoutCredentials(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/agent/info" {
			t.Error("unexpected request")
		}
		json.NewEncoder(w).Encode(map[string]any{"policy": defaultPolicy()})
	}))
	defer server.Close()
	a, err := newAgent(Config{Listen: "127.0.0.1:8765"}, t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	a.configPath = filepath.Join(a.root, "agent-config.json")
	if err := a.configure(context.Background(), Config{ServerURL: server.URL, DeviceName: "Office PC", Listen: "0.0.0.0:80"}); err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile(a.configPath)
	if err != nil {
		t.Fatal(err)
	}
	var config map[string]any
	json.Unmarshal(data, &config)
	if len(config) != 3 || config["listen"] != "127.0.0.1:8765" || config["server_url"] != server.URL {
		t.Fatalf("unexpected config: %v", config)
	}
	a.session = &Session{User: User{ID: "employee"}, ExpiresAt: time.Now().Add(time.Hour)}
	if a.configure(context.Background(), Config{ServerURL: server.URL, DeviceName: "Other"}) == nil {
		t.Fatal("allowed settings change while signed in")
	}
	a.session = nil
	server.Close()
	if a.configure(context.Background(), Config{ServerURL: server.URL, DeviceName: "Other"}) == nil {
		t.Fatal("saved unreachable server")
	}
	if a.config.DeviceName != "Office PC" {
		t.Fatal("failed update changed live configuration")
	}
	after, _ := os.ReadFile(a.configPath)
	if string(after) != string(data) {
		t.Fatal("failed update changed saved configuration")
	}
}

func TestConfigEndpointRejectsCrossOrigin(t *testing.T) {
	handler := agentHandler(&Agent{}, "http://127.0.0.1:8765", "secret")
	request := httptest.NewRequest("POST", "http://127.0.0.1:8765/local/config", nil)
	request.Header.Set("Origin", "https://other.example")
	request.Header.Set("Content-Type", "application/json")
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, request)
	if response.Code != 403 {
		t.Fatal("cross origin settings write accepted")
	}
}
