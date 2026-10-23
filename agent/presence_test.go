package main

import (
	"context"
	"errors"
	"sync/atomic"
	"testing"
	"time"
)

func TestPresenceThresholdAndUnavailable(t *testing.T) {
	for _, tc := range []struct {
		seconds   uint32
		available bool
		state     string
	}{
		{0, true, "active"}, {299, true, "active"}, {300, true, "idle"}, {3600, true, "idle"}, {0, false, "unavailable"}, {400, false, "unavailable"},
	} {
		if got := classifyPresence(tc.seconds, tc.available); got.State != tc.state {
			t.Fatalf("%+v: got %s", tc, got.State)
		}
	}
}

func TestInactiveWorkNeverStarts(t *testing.T) {
	for _, state := range []string{"idle", "unavailable"} {
		called := false
		err := whileActive(context.Background(), func() Presence { return Presence{State: state} }, time.Millisecond, func(context.Context) error { called = true; return nil })
		if called || !errors.Is(err, errInactive) {
			t.Fatal("inactive screenshot work was allowed")
		}
	}
}
func TestActiveWorkCancelledOnIdleAndAllowedOnResume(t *testing.T) {
	var active atomic.Bool
	active.Store(true)
	probe := func() Presence {
		if active.Load() {
			return Presence{State: "active"}
		}
		return Presence{State: "idle"}
	}
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	err := whileActive(ctx, probe, time.Millisecond, func(work context.Context) error { active.Store(false); <-work.Done(); return work.Err() })
	if !errors.Is(err, context.Canceled) || ctx.Err() != nil {
		t.Fatal("work was not promptly cancelled")
	}
	active.Store(true)
	if err := whileActive(ctx, probe, time.Millisecond, func(context.Context) error { return nil }); err != nil {
		t.Fatal(err)
	}
}
