package main

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"time"
)

type AnomalyEvidence struct {
	EventID       string    `json:"event_id"`
	NoticeVersion int       `json:"notice_version"`
	StartedAt     time.Time `json:"started_at"`
	EndedAt       time.Time `json:"ended_at"`
	IntervalCount int       `json:"interval_count"`
	RegularCount  int       `json:"regular_count"`
	PeriodMS      int       `json:"period_ms"`
}

func (a *Agent) optionalPresence(session Session) Presence {
	a.Lock()
	valid := a.session != nil && a.session.Token == session.Token && a.policy.Version == session.Policy.Version && a.policy.ModulesConsented && time.Since(a.lastContact) < 60*time.Second
	a.Unlock()
	if !valid {
		return Presence{State: "unavailable"}
	}
	return presenceFor(session.Policy)
}
func encoderPath() (string, error) {
	exe, err := os.Executable()
	if err == nil {
		path := filepath.Join(filepath.Dir(exe), "ffmpeg.exe")
		if info, e := os.Stat(path); e == nil && info.Mode().IsRegular() {
			return path, nil
		}
	}
	return exec.LookPath("ffmpeg")
}
func videoArguments(seconds int, path string) []string {
	return []string{"-hide_banner", "-loglevel", "error", "-nostdin", "-y", "-f", "gdigrab", "-framerate", "2", "-draw_mouse", "0", "-i", "desktop", "-t", strconv.Itoa(seconds), "-an", "-vf", "scale=1280:720:force_original_aspect_ratio=decrease:force_divisible_by=2", "-c:v", "libx264", "-preset", "ultrafast", "-crf", "30", "-pix_fmt", "yuv420p", "-movflags", "+faststart", "-fs", "20971520", path}
}
func encodeScreen(ctx context.Context, seconds int, path string) error {
	if runtime.GOOS != "windows" {
		return errors.New("screen video is supported on Windows only")
	}
	binary, err := encoderPath()
	if err != nil {
		return errors.New("video recording needs FFmpeg: install it or place ffmpeg.exe beside the agent")
	}
	limited, cancel := context.WithTimeout(ctx, time.Duration(seconds+15)*time.Second)
	defer cancel()
	command := exec.CommandContext(limited, binary, videoArguments(seconds, path)...)
	hideCommand(command)
	if err := command.Run(); err != nil {
		return errors.New("video capture stopped or encoder unavailable")
	}
	return nil
}
func (a *Agent) recordVideos(ctx context.Context, session Session) {
	if !session.Policy.VideoEnabled || !session.Policy.ModulesConsented {
		return
	}
	for {
		if ctx.Err() != nil {
			return
		}
		if a.optionalPresence(session).State == "active" {
			a.videoMu.Lock()
			id := uuid()
			path := filepath.Join(a.root, "payloads", id+".mp4")
			captured := time.Now().UTC()
			a.Lock()
			a.videoRecording = true
			a.videoMessage = "Recording a silent screen clip"
			a.Unlock()
			err := whileActive(ctx, func() Presence { return a.optionalPresence(session) }, time.Second, func(active context.Context) error { return encodeScreen(active, session.Policy.VideoSeconds, path) })
			a.Lock()
			a.videoRecording = false
			a.videoMessage = "Video clip finished"
			if err != nil {
				a.videoMessage = err.Error()
			}
			a.Unlock()
			if err == nil && ctx.Err() == nil && a.optionalPresence(session).State == "active" {
				info, e := os.Stat(path)
				if e == nil && info.Size() > 0 && info.Size() <= 20*1024*1024 {
					err = a.queue.add(Job{Event: Event{ID: id, CapturedAt: captured}, Kind: "video", UserID: session.User.ID, File: id + ".mp4", Bytes: info.Size(), NoticeVersion: session.Policy.Version}, session.Policy.QueueMB)
				} else {
					err = errors.New("invalid video size")
				}
			} else {
				err = errInactive
			}
			if err != nil {
				os.Remove(path)
			}
			a.videoMu.Unlock()
		}
		if !waitFor(ctx, time.Duration(session.Policy.VideoInterval)*time.Second) {
			return
		}
	}
}

// The histogram contains timing buckets only; raw input timestamps are never persisted.
func timingEvidence(start, end time.Time, intervals []uint32) *AnomalyEvidence {
	if end.Sub(start) < 10*time.Minute || end.Sub(start) > 20*time.Minute || len(intervals) < 30 {
		return nil
	}
	histogram := map[int]int{}
	bestPeriod, bestCount := 0, 0
	for _, delta := range intervals {
		bucket := int((delta+100)/200) * 200
		if bucket < 2000 || bucket > 60000 {
			continue
		}
		histogram[bucket]++
		if histogram[bucket] > bestCount {
			bestPeriod, bestCount = bucket, histogram[bucket]
		}
	}
	if bestCount*10 < len(intervals)*9 {
		return nil
	}
	return &AnomalyEvidence{StartedAt: start, EndedAt: end, IntervalCount: len(intervals), RegularCount: bestCount, PeriodMS: bestPeriod}
}
func (a *Agent) observeTiming(ctx context.Context, session Session) {
	if !session.Policy.AnomalyEnabled || !session.Policy.ModulesConsented {
		return
	}
	var start time.Time
	var previous uint32
	intervals := []uint32{}
	for {
		if ctx.Err() != nil {
			return
		}
		p := a.optionalPresence(session)
		if p.State != "active" {
			start = time.Time{}
			previous = 0
			intervals = nil
		} else {
			now := time.Now().UTC()
			if start.IsZero() {
				start = now
				previous = p.InputTick
			}
			if p.InputTick != 0 && previous != 0 && p.InputTick != previous {
				intervals = append(intervals, p.InputTick-previous)
				previous = p.InputTick
			}
			if now.Sub(start) >= 10*time.Minute {
				if evidence := timingEvidence(start, now, intervals); evidence != nil {
					id := uuid()
					evidence.EventID = id
					evidence.NoticeVersion = session.Policy.Version
					if err := a.queue.add(Job{Event: Event{ID: id, CapturedAt: now}, Kind: "anomaly", UserID: session.User.ID, NoticeVersion: session.Policy.Version, Anomaly: evidence}, session.Policy.QueueMB); err != nil {
						a.setUploadMessage(err.Error())
					}
				}
				start = now
				intervals = nil
			}
		}
		if !waitFor(ctx, time.Second) {
			return
		}
	}
}
func (a *Agent) uploadOptional(ctx context.Context, session Session, job Job) {
	if !session.Policy.ModulesConsented || job.NoticeVersion != session.Policy.Version {
		return
	}
	if job.Kind == "video" {
		if !session.Policy.VideoEnabled || a.optionalPresence(session).State != "active" {
			return
		}
		err := whileActive(ctx, func() Presence { return a.optionalPresence(session) }, time.Second, func(c context.Context) error { return a.client.upload(c, session.Token, a.root, job) })
		if ctx.Err() == nil {
			a.finishJob(job, err)
		}
		return
	}
	if !session.Policy.AnomalyEnabled || a.optionalPresence(session).State != "active" {
		return
	}
	var result struct {
		Accepted bool `json:"accepted"`
	}
	err := a.client.request(ctx, "/api/agent/anomalies", session.Token, job.Anomaly, &result)
	if err == nil && !result.Accepted {
		err = errors.New("server did not confirm review evidence")
	}
	if ctx.Err() == nil {
		a.finishJob(job, err)
	}
}
