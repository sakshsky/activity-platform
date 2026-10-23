package main

import (
	"context"
	"io"
	"net/http"
	"time"
)

func (a *Agent) serverScreenshot(w http.ResponseWriter, r *http.Request) {
	id := r.URL.Query().Get("id")
	if !validID(id) {
		http.Error(w, "Invalid screenshot identifier", 400)
		return
	}
	a.Lock()
	session := a.session
	client := a.client
	a.Unlock()
	if session == nil || !session.ExpiresAt.After(time.Now()) {
		http.Error(w, "Sign in again", 401)
		return
	}
	isVideo := r.URL.Path == "/local/my-video"
	path := client.base + "/api/agent/my-screenshots/" + id
	if isVideo {
		path = client.base + "/api/agent/videos/" + id
	}
	if r.URL.Query().Get("thumbnail") == "1" {
		path += "?thumbnail=1"
	}
	ctx, cancel := context.WithTimeout(r.Context(), 50*time.Second)
	defer cancel()
	request, err := http.NewRequestWithContext(ctx, "GET", path, nil)
	if err != nil {
		http.Error(w, "Invalid server", 502)
		return
	}
	request.Header.Set("Authorization", "Bearer "+session.Token)
	if isVideo && r.Header.Get("Range") != "" {
		request.Header.Set("Range", r.Header.Get("Range"))
	}
	request.Header.Set("Accept", "image/png,image/jpeg,application/json")
	response, err := client.http.Do(request)
	if err != nil {
		http.Error(w, "Cannot load screenshot from server", 502)
		return
	}
	defer response.Body.Close()
	if response.StatusCode != 200 && !(isVideo && response.StatusCode == 206) {
		http.Error(w, "Screenshot unavailable", response.StatusCode)
		return
	}
	contentType := response.Header.Get("Content-Type")
	if (isVideo && contentType != "video/mp4") || (!isVideo && contentType != "image/png" && contentType != "image/jpeg") {
		http.Error(w, "Invalid screenshot response", 502)
		return
	}
	bytes, err := io.ReadAll(io.LimitReader(response.Body, 20*1024*1024+1))
	if err != nil || len(bytes) > 20*1024*1024 {
		http.Error(w, "Invalid screenshot size", 502)
		return
	}
	a.Lock()
	valid := a.session != nil && a.session.Token == session.Token && a.session.ExpiresAt.After(time.Now())
	a.Unlock()
	if !valid {
		http.Error(w, "Sign in again", 401)
		return
	}
	w.Header().Set("Content-Type", contentType)
	if isVideo {
		w.Header().Set("Accept-Ranges", "bytes")
		if value := response.Header.Get("Content-Range"); value != "" {
			w.Header().Set("Content-Range", value)
		}
		w.WriteHeader(response.StatusCode)
	}
	w.Write(bytes)
}
