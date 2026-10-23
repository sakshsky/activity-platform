package main

import (
	"context"
	"encoding/json"
	"errors"
	"net/url"
	"strings"
	"time"
)

func (a *Agent) configure(parent context.Context, input Config) error {
	a.transition.Lock()
	defer a.transition.Unlock()
	if a.sessionCopy() != nil {
		return errors.New("sign out before changing connection settings")
	}
	input.ServerURL = strings.TrimRight(strings.TrimSpace(input.ServerURL), "/")
	input.DeviceName = strings.TrimSpace(input.DeviceName)
	address, err := url.Parse(input.ServerURL)
	if err != nil || !secureServer(input.ServerURL) || address.User != nil || address.RawQuery != "" || address.Fragment != "" || address.Path != "" {
		return errors.New("enter the HTTPS root address of your server, for example https://ourteam.test")
	}
	if input.DeviceName == "" || len([]rune(input.DeviceName)) > 120 {
		return errors.New("enter a device name of 1 to 120 characters")
	}
	// Listener changes are deliberately not exposed: the current browser uses it.
	input.Listen = a.config.Listen
	client := newClient(input.ServerURL)
	ctx, cancel := context.WithTimeout(parent, 15*time.Second)
	defer cancel()
	var info struct {
		Policy Policy `json:"policy"`
	}
	if err := client.request(ctx, "/api/agent/info", "", nil, &info); err != nil {
		return errors.New("cannot connect to this server: " + err.Error())
	}
	if info.Policy.Notice == "" {
		return errors.New("server did not return a valid capture policy")
	}
	data, err := json.MarshalIndent(input, "", "  ")
	if err != nil {
		return err
	}
	if a.configPath == "" {
		return errors.New("configuration path is unavailable")
	}
	if err := atomicWrite(a.configPath, data); err != nil {
		return errors.New("cannot save settings: " + err.Error())
	}
	a.Lock()
	a.config = input
	a.client = client
	a.policy = info.Policy.bounded()
	a.lastContact = time.Now()
	a.message = "Settings saved. Sign in to connect."
	a.Unlock()
	return nil
}
