package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

type APIError struct {
	Code    int
	Message string
}

func (e *APIError) Error() string { return e.Message }

type Client struct {
	base string
	http *http.Client
}

func newClient(base string) *Client {
	return &Client{base: base, http: &http.Client{Timeout: 50 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}}
}
func (c *Client) request(ctx context.Context, path, token string, input any, output any) error {
	var body io.Reader
	method := "GET"
	if input != nil {
		data, err := json.Marshal(input)
		if err != nil {
			return err
		}
		body = bytes.NewReader(data)
		method = "POST"
	}
	req, err := http.NewRequestWithContext(ctx, method, c.base+path, body)
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	return c.send(req, token, output)
}
func (c *Client) send(req *http.Request, token string, output any) error {
	req.Header.Set("Accept", "application/json")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	response, err := c.http.Do(req)
	if err != nil {
		return errors.New("server connection failed; records remain queued")
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		var body struct {
			Message string `json:"message"`
		}
		json.NewDecoder(io.LimitReader(response.Body, 8192)).Decode(&body)
		if body.Message == "" {
			body.Message = fmt.Sprintf("Server returned HTTP %d", response.StatusCode)
		}
		return &APIError{response.StatusCode, body.Message}
	}
	if output != nil {
		if err := json.NewDecoder(io.LimitReader(response.Body, 1024*1024)).Decode(output); err != nil {
			return errors.New("server confirmation could not be read; record retained")
		}
	}
	return nil
}
func (c *Client) upload(ctx context.Context, token, root string, job Job) error {
	file, err := os.Open(filepath.Join(root, "payloads", job.File))
	if err != nil {
		return err
	}
	defer file.Close()
	var buffer bytes.Buffer
	writer := multipart.NewWriter(&buffer)
	part, err := writer.CreateFormFile("file", job.File)
	if err != nil {
		return err
	}
	if _, err = io.Copy(part, file); err != nil {
		return err
	}
	for key, value := range map[string]string{"event_id": job.ID, "captured_at": job.CapturedAt.UTC().Format(time.RFC3339Nano), "app_name": job.App, "window_title": job.Title, "url": job.URL} {
		if err := writer.WriteField(key, value); err != nil {
			return err
		}
	}
	if err := writer.Close(); err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, "POST", c.base+"/api/agent/screenshots", &buffer)
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", writer.FormDataContentType())
	var result struct {
		Accepted bool `json:"accepted"`
	}
	if err := c.send(req, token, &result); err != nil {
		return err
	}
	if !result.Accepted {
		return errors.New("server did not acknowledge screenshot")
	}
	return nil
}
