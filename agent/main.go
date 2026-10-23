package main

import (
	"context"
	"crypto/subtle"
	"embed"
	"encoding/json"
	"flag"
	"fmt"
	"io/fs"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

//go:embed ui/*
var webFiles embed.FS

func main() {
	configFile := flag.String("config", "agent-config.json", "administrator-managed configuration file")
	noBrowser := flag.Bool("no-browser", false, "do not open the local status window automatically")
	flag.Parse()
	executable, err := os.Executable()
	if err != nil {
		log.Fatal(err)
	}
	root := filepath.Dir(executable)
	path := *configFile
	if !filepath.IsAbs(path) {
		path = filepath.Join(root, path)
	}
	data, err := os.ReadFile(path)
	if err != nil && !os.IsNotExist(err) {
		log.Fatal("Cannot read agent configuration: ", err)
	}
	var config Config
	if len(data) > 0 && json.Unmarshal(data, &config) != nil {
		log.Fatal("Invalid agent configuration")
	}
	config.ServerURL = strings.TrimRight(config.ServerURL, "/")
	if config.ServerURL != "" && !secureServer(config.ServerURL) {
		log.Fatal("Server URL must use HTTPS or an HTTP loopback IP.")
	}
	if config.DeviceName == "" {
		config.DeviceName, _ = os.Hostname()
	}
	if config.Listen == "" {
		config.Listen = "127.0.0.1:8765"
	}
	host, _, err := net.SplitHostPort(config.Listen)
	if err != nil || net.ParseIP(host) == nil || !net.ParseIP(host).IsLoopback() {
		log.Fatal("The local agent must listen on a loopback IP.")
	}
	listener, err := net.Listen("tcp", config.Listen)
	if err != nil {
		log.Fatal("Cannot start local agent. It may already be running: ", err)
	}
	agent, err := newAgent(config, filepath.Join(root, "data"))
	if err != nil {
		log.Fatal(err)
	}
	agent.configPath = path
	origin := "http://" + listener.Addr().String()
	uiToken := uuid() + uuid()
	mux := agentHandler(agent, origin, uiToken)
	server := &http.Server{Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt)
	defer cancel()
	go agent.background(ctx)
	go runTray(ctx, origin+"/agent", func() string {
		agent.Lock()
		defer agent.Unlock()
		if agent.videoRecording {
			return "RECORDING SCREEN VIDEO — click to view"
		}
		if agent.tracking {
			return "Capture active — click to view"
		}
		return "Signed out / paused — click to view"
	})
	go func() {
		<-ctx.Done()
		agent.Lock()
		if agent.cancel != nil {
			agent.cancel()
		}
		agent.Unlock()
		server.Close()
	}()
	fmt.Printf("Activity agent running at %s/agent\nCapture is visible and follows the server policy after login.\nKeep this console open. Press Ctrl+C to exit.\n", origin)
	if !*noBrowser {
		openBrowser(origin + "/agent")
	}
	if err := server.Serve(listener); err != nil && err != http.ErrServerClosed {
		log.Fatal(err)
	}
}
func openBrowser(address string) {
	var command *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		command = exec.Command("rundll32", "url.dll,FileProtocolHandler", address)
	case "darwin":
		command = exec.Command("open", address)
	default:
		command = exec.Command("xdg-open", address)
	}
	hideCommand(command)
	if command.Start() == nil {
		go command.Wait()
	}
}
func agentHandler(agent *Agent, origin, uiToken string) http.Handler {
	mux := http.NewServeMux()
	authenticated := func(r *http.Request) bool {
		cookie, err := r.Cookie("activity_agent")
		return err == nil && subtle.ConstantTimeCompare([]byte(cookie.Value), []byte(uiToken)) == 1
	}
	writeAllowed := func(w http.ResponseWriter, r *http.Request) bool {
		if r.Method != "POST" {
			http.Error(w, "Method not allowed", 405)
			return false
		}
		if r.Header.Get("Origin") != origin || !strings.HasPrefix(r.Header.Get("Content-Type"), "application/json") {
			http.Error(w, "Invalid request origin", 403)
			return false
		}
		return true
	}
	mux.HandleFunc("/local/system-check", func(w http.ResponseWriter, r *http.Request) {
		if !authenticated(r) {
			http.Error(w, "Login required", 401)
			return
		}
		if !writeAllowed(w, r) {
			return
		}
		agent.systemChecks(w, r)
	})
	mux.HandleFunc("/local/status", func(w http.ResponseWriter, r *http.Request) {
		if !authenticated(r) {
			http.Error(w, "Login required", 401)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(agent.status(authenticated(r)))
	})
	for _, path := range []string{"/local/my-screenshot/delete", "/local/modules-consent", "/local/videos", "/local/my-video", "/local/anomalies", "/local/anomaly-context", "/local/performance", "/local/task-submit", "/local/work-context", "/local/portal", "/local/profile", "/local/leave", "/local/leave-cancel", "/local/my-data", "/local/my-screenshot"} {
		mux.HandleFunc(path, func(w http.ResponseWriter, r *http.Request) {
			if !authenticated(r) {
				http.Error(w, "Login required", 401)
				return
			}
			if r.URL.Path == "/local/videos" || r.URL.Path == "/local/my-video" || r.URL.Path == "/local/anomalies" || r.URL.Path == "/local/performance" || r.URL.Path == "/local/portal" || r.URL.Path == "/local/my-data" || r.URL.Path == "/local/my-screenshot" {
				if r.Method != "GET" {
					http.Error(w, "Method not allowed", 405)
					return
				}
			} else if !writeAllowed(w, r) {
				return
			}
			if r.URL.Path == "/local/my-screenshot" || r.URL.Path == "/local/my-video" {
				agent.serverScreenshot(w, r)
				return
			}
			agent.portal(w, r)
		})
	}
	mux.HandleFunc("/local/screenshot-preview", func(w http.ResponseWriter, r *http.Request) {
		if !authenticated(r) {
			http.Error(w, "Login required", http.StatusUnauthorized)
			return
		}
		agent.servePreview(w, r)
	})
	mux.HandleFunc("/local/login", func(w http.ResponseWriter, r *http.Request) {
		if !writeAllowed(w, r) {
			return
		}
		var input struct {
			Email    string `json:"email"`
			Password string `json:"password"`
			Accepted bool   `json:"notice_accepted"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 8192)).Decode(&input); err != nil {
			http.Error(w, "Invalid login request", 400)
			return
		}
		if err := agent.login(r.Context(), input.Email, input.Password, input.Accepted); err != nil {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(422)
			json.NewEncoder(w).Encode(map[string]string{"message": err.Error()})
			return
		}
		http.SetCookie(w, &http.Cookie{Name: "activity_agent", Value: uiToken, Path: "/", HttpOnly: true, SameSite: http.SameSiteStrictMode})
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]bool{"success": true})
	})
	mux.HandleFunc("/local/config", func(w http.ResponseWriter, r *http.Request) {
		if !writeAllowed(w, r) {
			return
		}
		var input Config
		if json.NewDecoder(http.MaxBytesReader(w, r.Body, 8192)).Decode(&input) != nil {
			http.Error(w, "Invalid settings", 400)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		if err := agent.configure(r.Context(), input); err != nil {
			w.WriteHeader(422)
			json.NewEncoder(w).Encode(map[string]string{"message": err.Error()})
			return
		}
		json.NewEncoder(w).Encode(map[string]bool{"success": true})
	})
	mux.HandleFunc("/local/logout", func(w http.ResponseWriter, r *http.Request) {
		if !writeAllowed(w, r) {
			return
		}
		if !authenticated(r) {
			http.Error(w, "Login required", 401)
			return
		}
		agent.logout()
		// Browser access is separate from employee authentication; keep setup/status usable.
		http.SetCookie(w, &http.Cookie{Name: "activity_agent", Value: uiToken, Path: "/", HttpOnly: true, SameSite: http.SameSiteStrictMode})
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, "{}")
	})
	ui, _ := fs.Sub(webFiles, "ui")
	mux.Handle("/ui/", http.StripPrefix("/ui/", http.FileServer(http.FS(ui))))
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/" {
			http.Redirect(w, r, "/agent", 303)
			return
		}
		if r.URL.Path != "/agent" {
			http.NotFound(w, r)
			return
		}
		data, err := fs.ReadFile(ui, "index.html")
		if err != nil {
			http.Error(w, "Build the Vue frontend first.", 503)
			return
		}
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		// Bootstrap browser access before login so a new installation can show setup.
		// Employee records still require an authenticated server session.
		http.SetCookie(w, &http.Cookie{Name: "activity_agent", Value: uiToken, Path: "/", HttpOnly: true, SameSite: http.SameSiteStrictMode})
		w.Write(data)
	})
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if "http://"+r.Host != origin {
			http.Error(w, "Invalid host", 403)
			return
		}
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Frame-Options", "DENY")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		mux.ServeHTTP(w, r)
	})
}
