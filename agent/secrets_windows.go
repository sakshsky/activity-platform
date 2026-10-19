package main

import (
	"bytes"
	"context"
	"encoding/base64"
	"errors"
	"os/exec"
	"strings"
	"syscall"
	"time"
)

func hideCommand(command *exec.Cmd) { command.SysProcAttr = &syscall.SysProcAttr{HideWindow: true} }

// Windows DPAPI binds saved connection details to the current Windows account.
func protectSettings(data []byte, encrypt bool) ([]byte, error) {
	action := "Unprotect"
	if encrypt {
		action = "Protect"
	}
	script := "Add-Type -AssemblyName System.Security; $ErrorActionPreference='Stop'; $data=[Convert]::FromBase64String([Console]::In.ReadToEnd()); $result=[Security.Cryptography.ProtectedData]::" + action + "($data,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Out.Write([Convert]::ToBase64String($result))"
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	hideCommand(command)
	command.Stdin = strings.NewReader(base64.StdEncoding.EncodeToString(data))
	var stdout bytes.Buffer
	command.Stdout = &stdout
	if err := command.Run(); err != nil {
		return nil, errors.New("Windows settings encryption is unavailable")
	}
	return base64.StdEncoding.DecodeString(strings.TrimSpace(stdout.String()))
}
