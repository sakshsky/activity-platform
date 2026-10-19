//go:build !windows

package main

import (
	"context"
	"errors"
)

func readForeground(ctx context.Context, wantURLs bool) (Activity, error) {
	return Activity{}, errors.New("activity tracking requires Windows")
}
