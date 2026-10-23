package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

type Queue struct {
	sync.Mutex
	root string
	jobs map[string]Job
	used int64
}

var ErrQueueFull = errors.New("offline queue is full; new records are skipped until uploads free space")

func openQueue(root string) (*Queue, error) {
	q := &Queue{root: root, jobs: map[string]Job{}}
	for _, folder := range []string{"outbox", "payloads", "quarantine"} {
		if err := os.MkdirAll(filepath.Join(root, folder), 0700); err != nil {
			return nil, err
		}
	}
	entries, err := os.ReadDir(filepath.Join(root, "outbox"))
	if err != nil {
		return nil, err
	}
	for _, entry := range entries {
		if entry.IsDir() || filepath.Ext(entry.Name()) != ".json" {
			continue
		}
		bytes, err := os.ReadFile(filepath.Join(root, "outbox", entry.Name()))
		if err != nil {
			return nil, err
		}
		var job Job
		if json.Unmarshal(bytes, &job) != nil || !validID(job.ID) || job.UserID == "" || job.CapturedAt.IsZero() || job.Attempts < 0 || job.Attempts > 100 || entry.Name() != job.ID+".json" || (job.Kind != "activity" && job.Kind != "screenshot" && job.Kind != "video" && job.Kind != "anomaly") || (job.Kind == "screenshot" && job.File != job.ID+".png") || ((job.Kind == "activity" || job.Kind == "anomaly") && job.File != "") || (job.Kind == "video" && job.File != job.ID+".mp4") || (job.Kind == "anomaly" && job.Anomaly == nil) {
			if err := q.quarantine(entry.Name()); err != nil {
				return nil, err
			}
			continue
		}
		if job.Kind == "screenshot" || job.Kind == "video" {
			info, err := os.Lstat(filepath.Join(root, "payloads", job.File))
			if err != nil || !info.Mode().IsRegular() {
				if err := q.quarantine(entry.Name()); err != nil {
					return nil, err
				}
				continue
			}
			job.Bytes = info.Size() + int64(len(bytes)) + 256
		} else {
			job.Bytes = int64(len(bytes)) + 256
		}
		q.jobs[job.ID] = job
		q.used += job.Bytes
	}
	quarantined, _ := os.ReadDir(filepath.Join(root, "quarantine"))
	for _, entry := range quarantined {
		if info, err := entry.Info(); err == nil && info.Mode().IsRegular() {
			q.used += info.Size()
		}
	}
	// Only orphan payloads from interrupted writes are removed; queued files stay.
	payloads, _ := os.ReadDir(filepath.Join(root, "payloads"))
	for _, file := range payloads {
		if file.IsDir() {
			continue
		}
		name := file.Name()
		id := name[:len(name)-len(filepath.Ext(name))]
		if validID(id) {
			if _, ok := q.jobs[id]; !ok {
				os.Remove(filepath.Join(root, "payloads", name))
			}
		}
	}
	return q, nil
}
func (q *Queue) add(job Job, maxMB int) error {
	q.Lock()
	defer q.Unlock()
	if !validID(job.ID) || job.UserID == "" {
		return errors.New("invalid queued event")
	}
	if _, ok := q.jobs[job.ID]; ok {
		return nil
	}
	data, _ := json.Marshal(job)
	// Reserve space for retry timestamps, counters and the terminal failure marker.
	job.Bytes += int64(len(data)) + 256
	if q.used+job.Bytes > int64(maxMB)*1024*1024 {
		return ErrQueueFull
	}
	if err := writeJSON(filepath.Join(q.root, "outbox", job.ID+".json"), job); err != nil {
		return err
	}
	q.jobs[job.ID] = job
	q.used += job.Bytes
	return nil
}
func (q *Queue) ready(userID string, now time.Time, limit int) []Job {
	q.Lock()
	defer q.Unlock()
	jobs := []Job{}
	for _, job := range q.jobs {
		if job.UserID == userID && job.Failure == "" && !job.NextAttempt.After(now) {
			jobs = append(jobs, job)
		}
	}
	sort.Slice(jobs, func(i, j int) bool { return jobs[i].CapturedAt.Before(jobs[j].CapturedAt) })
	if len(jobs) > limit {
		jobs = jobs[:limit]
	}
	return jobs
}
func (q *Queue) ack(id string) error {
	q.Lock()
	defer q.Unlock()
	job, ok := q.jobs[id]
	if !ok {
		return nil
	}
	// Keep the manifest and accounting until payload cleanup succeeds.
	if job.File != "" {
		if err := os.Remove(filepath.Join(q.root, "payloads", job.File)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}
	if err := os.Remove(filepath.Join(q.root, "outbox", id+".json")); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	delete(q.jobs, id)
	q.used -= job.Bytes
	return nil
}
func (q *Queue) retry(id string) error {
	q.Lock()
	defer q.Unlock()
	job, ok := q.jobs[id]
	if !ok {
		return nil
	}
	job.Attempts++
	if job.Attempts >= 100 {
		job.Failure = "retry limit reached"
	}
	exponent := job.Attempts
	if exponent > 8 {
		exponent = 8
	}
	job.NextAttempt = time.Now().Add(time.Duration(1<<exponent) * time.Second)
	if err := writeJSON(filepath.Join(q.root, "outbox", id+".json"), job); err != nil {
		return err
	}
	q.jobs[id] = job
	return nil
}
func (q *Queue) reject(id string) error {
	q.Lock()
	defer q.Unlock()
	job, ok := q.jobs[id]
	if !ok {
		return nil
	}
	job.Failure = "server permanently rejected record"
	if err := writeJSON(filepath.Join(q.root, "outbox", id+".json"), job); err != nil {
		return err
	}
	q.jobs[id] = job
	return nil
}
func (q *Queue) failedCount() int {
	q.Lock()
	defer q.Unlock()
	count := 0
	for _, job := range q.jobs {
		if job.Failure != "" {
			count++
		}
	}
	return count
}

// Derive the payload name from the manifest filename, never untrusted JSON paths.
func (q *Queue) quarantine(name string) error {
	id := strings.TrimSuffix(name, ".json")
	if err := os.Rename(filepath.Join(q.root, "outbox", name), filepath.Join(q.root, "quarantine", name)); err != nil {
		return fmt.Errorf("quarantine record: %w", err)
	}
	if validID(id) {
		err := os.Rename(filepath.Join(q.root, "payloads", id+".png"), filepath.Join(q.root, "quarantine", id+".png"))
		if err != nil && !errors.Is(err, os.ErrNotExist) {
			return fmt.Errorf("quarantine payload: %w", err)
		}
	}
	return nil
}
func (q *Queue) prune(days int) int {
	q.Lock()
	ids := []string{}
	cutoff := time.Now().Add(-time.Duration(days) * 24 * time.Hour)
	for id, job := range q.jobs {
		if job.CapturedAt.Before(cutoff) {
			ids = append(ids, id)
		}
	}
	q.Unlock()
	count := 0
	for _, id := range ids {
		if q.ack(id) == nil {
			count++
		}
	}
	q.Lock()
	entries, _ := os.ReadDir(filepath.Join(q.root, "quarantine"))
	for _, entry := range entries {
		if info, err := entry.Info(); err == nil && info.Mode().IsRegular() && info.ModTime().Before(cutoff) {
			if os.Remove(filepath.Join(q.root, "quarantine", entry.Name())) == nil {
				q.used -= info.Size()
				count++
			}
		}
	}
	q.Unlock()
	return count
}
func (q *Queue) stats() (int, int64) { q.Lock(); defer q.Unlock(); return len(q.jobs), q.used }
