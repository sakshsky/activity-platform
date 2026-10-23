package main

import (
	"context"
	"errors"
	"time"
)

const idleThresholdSeconds = 300

type Presence struct {
	InputTick   uint32 `json:"-"`
	State       string `json:"state"`
	IdleSeconds uint32 `json:"idle_seconds"`
}

func classifyPresence(idle uint32, available bool) Presence {
	if !available {
		return Presence{State: "unavailable", IdleSeconds: idle}
	}
	if idle >= idleThresholdSeconds {
		return Presence{State: "idle", IdleSeconds: idle}
	}
	return Presence{State: "active", IdleSeconds: idle}
}

var errInactive = errors.New("screenshots paused while idle or locked")

// Cancel ongoing capture/upload work promptly if the workstation becomes inactive.
func whileActive(parent context.Context, probe func() Presence, interval time.Duration, action func(context.Context) error) error {
	if parent.Err() != nil {
		return parent.Err()
	}
	if probe().State != "active" {
		return errInactive
	}
	ctx, cancel := context.WithCancel(parent)
	done := make(chan struct{})
	go func() {
		defer close(done)
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				if probe().State != "active" {
					cancel()
					return
				}
			}
		}
	}()
	err := action(ctx)
	cancel()
	<-done
	return err
}

// Apply the current policy to the native idle reading without changing lock detection.
func presenceFor(policy Policy) Presence {
	p := readPresence()
	if p.State != "unavailable" {
		p.State = "active"
		if p.IdleSeconds >= uint32(policy.bounded().IdleThresholdSeconds) {
			p.State = "idle"
		}
	}
	return p
}
