//go:build !windows

package main

import (
	"context"
	"fmt"
)

func captureScreen(path string) error {
	return fmt.Errorf("screenshots require Windows")
}
func captureScreenContext(ctx context.Context, path string) error { return captureScreen(path) }
