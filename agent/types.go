package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"
)

type User struct {
	ID    string `json:"id"`
	Name  string `json:"name"`
	Email string `json:"email"`
}
type Policy struct {
	Version           int    `json:"version"`
	MonitoringEnabled bool   `json:"monitoring_enabled"`
	ScreenshotSeconds int    `json:"screenshot_interval_seconds"`
	ActivitySeconds   int    `json:"activity_interval_seconds"`
	BrowserURLs       bool   `json:"browser_urls"`
	QueueMB           int    `json:"offline_queue_max_mb"`
	OfflineDays       int    `json:"offline_retention_days"`
	Notice            string `json:"notice"`
}

func defaultPolicy() Policy {
	return Policy{ScreenshotSeconds: 120, ActivitySeconds: 5, QueueMB: 512, OfflineDays: 7, Notice: "This agent records desktop screenshots and foreground application activity while signed in, under your organization's monitoring policy. Contact the server to load the current notice before signing in."}
}
func (p Policy) bounded() Policy {
	if p.ScreenshotSeconds < 30 || p.ScreenshotSeconds > 3600 {
		p.ScreenshotSeconds = 120
	}
	if p.ActivitySeconds < 5 || p.ActivitySeconds > 300 {
		p.ActivitySeconds = 5
	}
	if p.QueueMB < 50 || p.QueueMB > 10240 {
		p.QueueMB = 512
	}
	if p.OfflineDays < 1 || p.OfflineDays > 30 {
		p.OfflineDays = 7
	}
	return p
}

type Config struct {
	ServerURL  string `json:"server_url"`
	DeviceName string `json:"device_name"`
	Listen     string `json:"listen"`
}
type Session struct {
	ServerURL string    `json:"server_url"`
	Token     string    `json:"token"`
	User      User      `json:"user"`
	ExpiresAt time.Time `json:"expires_at"`
	Device    struct {
		ID         string `json:"id"`
		Identifier string `json:"device_identifier"`
	} `json:"device"`
	Policy Policy `json:"policy"`
}
type Activity struct {
	App       string
	Title     string
	URL       string
	URLStatus string
}
type Event struct {
	ID         string    `json:"event_id"`
	CapturedAt time.Time `json:"captured_at"`
	App        string    `json:"app_name"`
	Title      string    `json:"window_title"`
	URL        string    `json:"url,omitempty"`
}
type Job struct {
	Event
	Kind        string    `json:"kind"`
	File        string    `json:"file,omitempty"`
	UserID      string    `json:"user_id"`
	Attempts    int       `json:"attempts"`
	NextAttempt time.Time `json:"next_attempt"`
	Bytes       int64     `json:"bytes"`
	Failure     string    `json:"failure,omitempty"`
}

func uuid() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(err)
	}
	b[6] = (b[6] & 15) | 64
	b[8] = (b[8] & 63) | 128
	h := hex.EncodeToString(b[:])
	return h[:8] + "-" + h[8:12] + "-" + h[12:16] + "-" + h[16:20] + "-" + h[20:]
}
func secureServer(address string) bool {
	u, err := url.Parse(address)
	if err != nil || u.Hostname() == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return false
	}
	ip := net.ParseIP(u.Hostname())
	return u.Scheme == "https" || (u.Scheme == "http" && ip != nil && ip.IsLoopback())
}
func writeJSON(path string, value any) error {
	data, err := json.Marshal(value)
	if err != nil {
		return err
	}
	return atomicWrite(path, data)
}
func atomicWrite(path string, data []byte) error {
	temp, err := os.CreateTemp(filepath.Dir(path), ".pending-*")
	if err != nil {
		return err
	}
	name := temp.Name()
	defer os.Remove(name)
	if err = temp.Chmod(0600); err == nil {
		_, err = temp.Write(data)
	}
	if err == nil {
		err = temp.Sync()
	}
	closeErr := temp.Close()
	if err == nil {
		err = closeErr
	}
	if err != nil {
		return err
	}
	return os.Rename(name, path)
}
func loadIdentity(root string) (string, error) {
	path := filepath.Join(root, "device-id")
	data, err := os.ReadFile(path)
	if err == nil {
		value := strings.TrimSpace(string(data))
		if !validID(value) {
			return "", errors.New("invalid saved device ID")
		}
		return value, nil
	}
	if !errors.Is(err, os.ErrNotExist) {
		return "", err
	}
	value := uuid()
	return value, atomicWrite(path, []byte(value))
}
func validID(value string) bool {
	if len(value) != 36 {
		return false
	}
	for i, c := range value {
		if i == 8 || i == 13 || i == 18 || i == 23 {
			if c != '-' {
				return false
			}
		} else if !strings.ContainsRune("0123456789abcdef", c) {
			return false
		}
	}
	return true
}
func waitFor(ctx context.Context, d time.Duration) bool {
	timer := time.NewTimer(d)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-timer.C:
		return ctx.Err() == nil
	}
}
func truncate(value string, length int) string {
	r := []rune(value)
	if len(r) > length {
		return string(r[:length])
	}
	return value
}
func cleanBrowserAddress(value string) string {
	value = strings.TrimSpace(value)
	if value == "" || strings.ContainsAny(value, "\r\n\t ") {
		return ""
	}
	scheme := strings.HasPrefix(value, "http://") || strings.HasPrefix(value, "https://")
	address := value
	if !scheme {
		if strings.Contains(value, "://") {
			return ""
		}
		address = "https://" + value
	}
	u, err := url.Parse(address)
	if err != nil || u.Hostname() == "" {
		return ""
	}
	host := u.Hostname()
	if !scheme && !strings.Contains(host, ".") && host != "localhost" && net.ParseIP(host) == nil {
		return ""
	}
	u.User = nil
	u.RawQuery = ""
	u.ForceQuery = false
	u.Fragment = ""
	u.RawFragment = ""
	if scheme {
		return u.String()
	}
	return u.Host + u.EscapedPath()
}
