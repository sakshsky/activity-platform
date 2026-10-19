package main

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"syscall"
	"time"
)

// Capture the entire virtual desktop (all monitors) using Windows' built-in APIs.
func captureScreen(path string) error {
	return captureScreenContext(context.Background(), path)
}

func captureScreenContext(parent context.Context, path string) error {
	ctx, cancel := context.WithTimeout(parent, 20*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-Command", captureScript)
	command.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	command.Env = append(os.Environ(), "LOCAL_CLOCK_SCREENSHOT="+path)
	output, err := command.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, output)
	}
	return nil
}

const captureScript = `
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type -TypeDefinition 'using System; using System.Runtime.InteropServices; public class ClockDpi { [DllImport("user32.dll")] public static extern bool SetProcessDPIAware(); [DllImport("user32.dll")] public static extern bool SetProcessDpiAwarenessContext(IntPtr value); [DllImport("user32.dll")] public static extern IntPtr SetThreadDpiAwarenessContext(IntPtr value); }'
try {
    [ClockDpi]::SetProcessDpiAwarenessContext([IntPtr](-4)) | Out-Null
    [ClockDpi]::SetThreadDpiAwarenessContext([IntPtr](-4)) | Out-Null
} catch { [ClockDpi]::SetProcessDPIAware() | Out-Null }
$bounds = [System.Windows.Forms.SystemInformation]::VirtualScreen
$bitmap = New-Object System.Drawing.Bitmap($bounds.Width, $bounds.Height)
try {
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    try {
        $graphics.CopyFromScreen($bounds.Left, $bounds.Top, 0, 0, $bounds.Size)
        $bitmap.Save($env:LOCAL_CLOCK_SCREENSHOT, [System.Drawing.Imaging.ImageFormat]::Png)
    } finally { $graphics.Dispose() }
} finally { $bitmap.Dispose() }
`
