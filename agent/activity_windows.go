package main

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unsafe"
)

var user32 = syscall.NewLazyDLL("user32.dll")
var getForegroundWindow = user32.NewProc("GetForegroundWindow")
var getWindowText = user32.NewProc("GetWindowTextW")
var getWindowThreadProcessID = user32.NewProc("GetWindowThreadProcessId")
var queryProcessName = syscall.NewLazyDLL("kernel32.dll").NewProc("QueryFullProcessImageNameW")

func readForeground(ctx context.Context, wantURLs bool) (Activity, error) {
	hwnd, _, _ := getForegroundWindow.Call()
	if hwnd == 0 {
		return Activity{}, errors.New("no foreground window")
	}
	var pid uint32
	getWindowThreadProcessID.Call(hwnd, uintptr(unsafe.Pointer(&pid)))
	handle, err := syscall.OpenProcess(0x1000, false, pid)
	if err != nil {
		return Activity{}, errors.New("cannot read foreground process")
	}
	defer syscall.CloseHandle(handle)
	var name [32768]uint16
	size := uint32(len(name))
	ok, _, _ := queryProcessName.Call(uintptr(handle), 0, uintptr(unsafe.Pointer(&name[0])), uintptr(unsafe.Pointer(&size)))
	if ok == 0 {
		return Activity{}, errors.New("cannot read foreground process name")
	}
	var title [4096]uint16
	getWindowText.Call(hwnd, uintptr(unsafe.Pointer(&title[0])), uintptr(len(title)))
	item := Activity{App: filepath.Base(syscall.UTF16ToString(name[:size])), Title: syscall.UTF16ToString(title[:])}
	if wantURLs {
		switch strings.ToLower(item.App) {
		case "chrome.exe", "msedge.exe", "firefox.exe", "brave.exe":
			item.URL = readBrowserAddress(ctx, hwnd)
			if item.URL == "" {
				item.URLStatus = "Browser address unavailable"
			}
		}
	}
	current, _, _ := getForegroundWindow.Call()
	if current != hwnd {
		return Activity{}, errors.New("foreground changed during sample")
	}
	return item, nil
}

func readBrowserAddress(parent context.Context, hwnd uintptr) string {
	ctx, cancel := context.WithTimeout(parent, 3*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-Command", browserAddressScript)
	hideCommand(command)
	command.Env = append(os.Environ(), "LOCAL_TRACKER_HWND="+strconv.FormatUint(uint64(hwnd), 10))
	output, err := command.Output()
	if err != nil {
		return ""
	}
	return cleanBrowserAddress(string(output))
}

// Only read specifically named address controls; never generic page form values.
const browserAddressScript = `
$ErrorActionPreference='Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$root=[System.Windows.Automation.AutomationElement]::FromHandle([IntPtr]([long]$env:LOCAL_TRACKER_HWND))
$edit=New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ControlTypeProperty,[System.Windows.Automation.ControlType]::Edit)
$items=$root.FindAll([System.Windows.Automation.TreeScope]::Descendants,$edit)
foreach($item in $items) {
    $name=$item.Current.Name
    $id=$item.Current.AutomationId
    if ($name -in @('Address and search bar','Search or enter address','Search with Google or enter address','Search with Bing or enter address') -or $id -in @('addressEditBox','urlbar-input','urlbar')) {
        if ($item.Current.HasKeyboardFocus) { continue }
        $parent=[System.Windows.Automation.TreeWalker]::ControlViewWalker.GetParent($item)
        $insidePage=$false
        while ($null -ne $parent -and $parent -ne $root) {
            if ($parent.Current.ControlType -eq [System.Windows.Automation.ControlType]::Document) { $insidePage=$true; break }
            $parent=[System.Windows.Automation.TreeWalker]::ControlViewWalker.GetParent($parent)
        }
        if ($insidePage) { continue }
        $pattern=$null
        if ($item.TryGetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern,[ref]$pattern)) {
            [Console]::Out.Write($pattern.Current.Value)
            break
        }
    }
}
`
