package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	mathrand "math/rand"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type Agent struct {
	sync.Mutex
	transition                             sync.Mutex
	logMu                                  sync.Mutex
	config                                 Config
	root, id                               string
	queue                                  *Queue
	client                                 *Client
	session                                *Session
	cancel                                 context.CancelFunc
	tracking                               bool
	lastContact                            time.Time
	policy                                 Policy
	message, uploadMessage, captureMessage string
}

func newAgent(config Config, root string) (*Agent, error) {
	if err := os.MkdirAll(root, 0700); err != nil {
		return nil, err
	}
	if err := os.MkdirAll(filepath.Join(root, "logs"), 0700); err != nil {
		return nil, err
	}
	id, err := loadIdentity(root)
	if err != nil {
		return nil, err
	}
	queue, err := openQueue(root)
	if err != nil {
		return nil, err
	}
	a := &Agent{config: config, root: root, id: id, queue: queue, client: newClient(config.ServerURL), policy: defaultPolicy(), message: "Sign in to enroll this device.", uploadMessage: "No uploads yet.", captureMessage: "Monitoring is stopped."}
	if sealed, err := os.ReadFile(filepath.Join(root, "session.bin")); err == nil {
		if plain, err := protectSettings(sealed, false); err == nil {
			var session Session
			if json.Unmarshal(plain, &session) == nil && session.ServerURL == config.ServerURL && session.ExpiresAt.After(time.Now()) {
				a.useSession(session)
			}
		}
	}
	return a, nil
}
func (a *Agent) sessionCopy() *Session {
	a.Lock()
	defer a.Unlock()
	if a.session == nil {
		return nil
	}
	copy := *a.session
	return &copy
}
func (a *Agent) useSession(session Session) {
	session.Policy = session.Policy.bounded()
	a.Lock()
	if a.cancel != nil {
		a.cancel()
		a.cancel = nil
	}
	a.session = &session
	a.policy = session.Policy
	a.tracking = session.Policy.MonitoringEnabled && session.ExpiresAt.After(time.Now())
	a.message = "Monitoring follows your organization's policy."
	if a.tracking {
		ctx, cancel := context.WithCancel(context.Background())
		a.cancel = cancel
		a.message = "Monitoring active. Screenshots and app activity are collected and uploaded."
		go a.capture(ctx, session)
		go a.activity(ctx, session)
		go a.uploads(ctx, session)
	} else {
		a.message = "Monitoring paused by the administrator."
		a.captureMessage = "Capture is paused."
	}
	message := a.message
	a.Unlock()
	a.localLog("session.policy", "", message)
}
func (a *Agent) persist(session Session) error {
	session.ServerURL = a.config.ServerURL
	data, err := json.Marshal(session)
	if err != nil {
		return err
	}
	sealed, err := protectSettings(data, true)
	if err != nil {
		return err
	}
	return atomicWrite(filepath.Join(a.root, "session.bin"), sealed)
}
func (a *Agent) login(ctx context.Context, email, password string, accepted bool) error {
	a.transition.Lock()
	defer a.transition.Unlock()
	if !accepted {
		return errors.New("read and accept the monitoring notice first")
	}
	var session Session
	err := a.client.request(ctx, "/api/agent/login", "", map[string]any{"email": email, "password": password, "device_identifier": a.id, "device_name": a.config.DeviceName, "os": "windows", "notice_accepted": true}, &session)
	if err != nil {
		return err
	}
	if session.Token == "" || session.User.ID == "" || !session.ExpiresAt.After(time.Now()) {
		return errors.New("server returned an invalid login confirmation")
	}
	session.ServerURL = a.config.ServerURL
	a.useSession(session)
	a.Lock()
	a.lastContact = time.Now()
	a.Unlock()
	if err := a.persist(session); err != nil {
		a.Lock()
		a.message += " Credentials could not be saved by Windows; sign in again after restarting."
		a.Unlock()
		a.localLog("credentials.not_saved", "", err.Error())
	}
	return nil
}
func (a *Agent) stopSession(message string) {
	a.Lock()
	if a.cancel != nil {
		a.cancel()
		a.cancel = nil
	}
	a.session = nil
	a.tracking = false
	a.message = message
	a.captureMessage = "Monitoring is stopped."
	a.Unlock()
	os.Remove(filepath.Join(a.root, "session.bin"))
	a.localLog("session.stopped", "", message)
}
func (a *Agent) logout() {
	a.transition.Lock()
	defer a.transition.Unlock()
	session := a.sessionCopy()
	a.stopSession("Signed out. Pending records remain on disk until the same employee reconnects or retention expires.")
	if session != nil {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		a.client.request(ctx, "/api/agent/logout", session.Token, map[string]any{}, nil)
	}
}
func (a *Agent) background(ctx context.Context) {
	for {
		a.refresh(ctx)
		a.Lock()
		days := a.policy.OfflineDays
		a.Unlock()
		if count := a.queue.prune(days); count > 0 {
			a.localLog("queue.expired", "", fmt.Sprintf("%d records exceeded offline retention", count))
		}
		a.pruneLogs()
		if !waitFor(ctx, 30*time.Second) {
			return
		}
	}
}
func (a *Agent) refresh(parent context.Context) {
	a.transition.Lock()
	defer a.transition.Unlock()
	ctx, cancel := context.WithTimeout(parent, 15*time.Second)
	defer cancel()
	session := a.sessionCopy()
	if session == nil {
		var info struct {
			Policy Policy `json:"policy"`
		}
		if a.client.request(ctx, "/api/agent/info", "", nil, &info) == nil {
			a.Lock()
			if a.session == nil {
				a.policy = info.Policy.bounded()
				a.lastContact = time.Now()
			}
			a.Unlock()
		}
		return
	}
	if !session.ExpiresAt.After(time.Now()) {
		a.stopSession("Session expired. Sign in again.")
		return
	}
	var result struct {
		Policy Policy `json:"policy"`
	}
	if err := a.client.request(ctx, "/api/agent/heartbeat", session.Token, map[string]any{}, &result); err != nil {
		current := a.sessionCopy()
		if current == nil || current.Token != session.Token {
			return
		}
		var apiErr *APIError
		if errors.As(err, &apiErr) && (apiErr.Code == 401 || apiErr.Code == 403) {
			a.stopSession("Device access revoked or account disabled. Contact your administrator.")
			return
		}
		a.Lock()
		a.message = "Server unavailable. Monitoring continues under the last received policy; uploads are queued within the storage limit."
		a.Unlock()
		return
	}
	current := a.sessionCopy()
	if current == nil || current.Token != session.Token {
		return
	}
	result.Policy = result.Policy.bounded()
	a.Lock()
	a.lastContact = time.Now()
	a.message = "Connected. Monitoring follows your organization's policy."
	a.Unlock()
	if result.Policy != session.Policy {
		session.Policy = result.Policy
		a.useSession(*session)
		a.persist(*session)
	}
}
func (a *Agent) status(showUser bool) map[string]any {
	a.Lock()
	defer a.Unlock()
	count, bytes := a.queue.stats()
	result := map[string]any{"device_identifier": a.id, "device_name": a.config.DeviceName, "server_url": a.config.ServerURL, "tracking": a.tracking, "message": a.message, "notice": a.policy.Notice, "policy": a.policy, "pending": count, "queue_mb": float64(bytes/1024) / 1024, "last_contact": a.lastContact, "upload_message": a.uploadMessage, "capture_message": a.captureMessage, "user": nil}
	if showUser && a.session != nil {
		result["user"] = a.session.User
	}
	if a.lastContact.IsZero() {
		result["last_contact"] = nil
	}
	result["failed_uploads"] = a.queue.failedCount()
	return result
}
func (a *Agent) activity(ctx context.Context, session Session) {
	for {
		if ctx.Err() != nil {
			return
		}
		sample, err := readForeground(ctx, session.Policy.BrowserURLs)
		if err == nil && ctx.Err() == nil {
			event := Event{ID: uuid(), CapturedAt: time.Now().UTC(), App: truncate(sample.App, 160), Title: truncate(sample.Title, 1000), URL: truncate(sample.URL, 2048)}
			if err := a.queue.add(Job{Event: event, Kind: "activity", UserID: session.User.ID}, session.Policy.QueueMB); err != nil {
				a.setUploadMessage(err.Error())
			}
		}
		if !waitFor(ctx, time.Duration(session.Policy.ActivitySeconds)*time.Second) {
			return
		}
	}
}
func (a *Agent) capture(ctx context.Context, session Session) {
	period := time.Duration(session.Policy.ScreenshotSeconds) * time.Second
	start := time.Now()
	for {
		start = currentCaptureWindow(start, time.Now(), period)
		base := time.Now()
		if base.Before(start) {
			base = start
		}
		remaining := start.Add(period).Sub(base)
		if remaining <= 0 {
			continue
		}
		target := base.Add(time.Duration(mathrand.Int63n(int64(remaining))))
		if !waitFor(ctx, time.Until(target)) {
			return
		}
		if time.Now().Before(start.Add(period)) {
			id := uuid()
			file := id + ".png"
			path := filepath.Join(a.root, "payloads", file)
			captured := time.Now().UTC()
			err := captureScreenContext(ctx, path)
			if ctx.Err() != nil {
				os.Remove(path)
				return
			}
			if err == nil {
				info, statErr := os.Stat(path)
				if statErr != nil {
					err = statErr
				} else if info.Size() > 20*1024*1024 {
					err = errors.New("screenshot exceeds the 20 MB server limit")
				} else {
					sample, _ := readForeground(ctx, session.Policy.BrowserURLs)
					if ctx.Err() != nil {
						os.Remove(path)
						return
					}
					event := Event{ID: id, CapturedAt: captured, App: truncate(sample.App, 160), Title: truncate(sample.Title, 1000), URL: truncate(sample.URL, 2048)}
					err = a.queue.add(Job{Event: event, Kind: "screenshot", UserID: session.User.ID, File: file, Bytes: info.Size()}, session.Policy.QueueMB)
				}
			}
			a.Lock()
			if err != nil {
				os.Remove(path)
				a.captureMessage = "Capture failed: " + err.Error()
			} else {
				a.captureMessage = "Screenshot queued at " + captured.Local().Format("15:04:05")
			}
			a.Unlock()
		}
		start = start.Add(period)
	}
}

// Preserve fixed windows, skipping elapsed windows without replaying captures.
func currentCaptureWindow(start, now time.Time, period time.Duration) time.Time {
	if !now.Before(start.Add(period)) {
		return start.Add((now.Sub(start) / period) * period)
	}
	return start
}
func (a *Agent) uploads(ctx context.Context, session Session) {
	for {
		if ctx.Err() != nil {
			return
		}
		jobs := a.queue.ready(session.User.ID, time.Now(), 50)
		activities := []Job{}
		for _, job := range jobs {
			if ctx.Err() != nil {
				return
			}
			if job.Kind == "activity" {
				activities = append(activities, job)
				continue
			}
			err := a.client.upload(ctx, session.Token, a.root, job)
			if ctx.Err() != nil {
				return
			}
			a.finishJob(job, err)
		}
		if len(activities) > 0 && ctx.Err() == nil {
			events := make([]Event, 0, len(activities))
			for _, job := range activities {
				events = append(events, job.Event)
			}
			var result struct {
				IDs []string `json:"accepted_ids"`
			}
			err := a.client.request(ctx, "/api/agent/activity", session.Token, map[string]any{"events": events}, &result)
			if ctx.Err() != nil {
				return
			}
			acknowledged := map[string]bool{}
			for _, id := range result.IDs {
				acknowledged[id] = true
			}
			for _, job := range activities {
				jobErr := err
				if jobErr == nil && !acknowledged[job.ID] {
					jobErr = errors.New("server did not confirm this event")
				}
				a.finishJob(job, jobErr)
			}
		}
		if !waitFor(ctx, 3*time.Second) {
			return
		}
	}
}
func (a *Agent) finishJob(job Job, err error) {
	if err != nil {
		var apiErr *APIError
		var queueErr error
		if errors.As(err, &apiErr) && (apiErr.Code == 400 || apiErr.Code == 413 || apiErr.Code == 415 || apiErr.Code == 422) {
			queueErr = a.queue.reject(job.ID)
		} else {
			queueErr = a.queue.retry(job.ID)
		}
		if queueErr != nil {
			a.setUploadMessage("Cannot persist upload failure: " + queueErr.Error())
			return
		}
		if a.queue.failedCount() > 0 {
			a.setUploadMessage("Rejected uploads retained locally for inspection until retention expires.")
			a.localLog("upload.retained_failure", job.ID, err.Error())
			return
		}
		a.setUploadMessage("Upload deferred: " + err.Error())
		a.localLog("upload.failed", job.ID, err.Error())
		return
	}
	if err = a.queue.ack(job.ID); err != nil {
		a.setUploadMessage("Server confirmed upload, but local cleanup failed.")
		return
	}
	a.setUploadMessage("Last successful upload: " + time.Now().Format("15:04:05"))
	a.localLog("upload.confirmed", job.ID, job.Kind)
}
func (a *Agent) setUploadMessage(message string) { a.Lock(); a.uploadMessage = message; a.Unlock() }
func (a *Agent) localLog(action, id, message string) {
	a.logMu.Lock()
	defer a.logMu.Unlock()
	file, err := os.OpenFile(filepath.Join(a.root, "logs", time.Now().UTC().Format("2006-01-02")+".jsonl"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0600)
	if err != nil {
		log.Print("Cannot write local upload log")
		return
	}
	defer file.Close()
	json.NewEncoder(file).Encode(map[string]any{"at": time.Now().UTC(), "action": action, "event_id": id, "message": message})
}
func (a *Agent) pruneLogs() {
	entries, _ := os.ReadDir(filepath.Join(a.root, "logs"))
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		stamp, err := time.Parse("2006-01-02.jsonl", entry.Name())
		if err == nil && stamp.Before(time.Now().Add(-30*24*time.Hour)) {
			os.Remove(filepath.Join(a.root, "logs", entry.Name()))
		}
	}
}
