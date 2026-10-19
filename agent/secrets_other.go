//go:build !windows

package main

import (
	"errors"
	"os/exec"
)

func hideCommand(command *exec.Cmd) {}
func protectSettings(data []byte, encrypt bool) ([]byte, error) {
	return nil, errors.New("saving upload settings requires Windows")
}
