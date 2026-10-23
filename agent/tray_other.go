//go:build !windows

package main

import "context"

func runTray(ctx context.Context, address string, tracking func() string) {}
