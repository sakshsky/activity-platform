package main

import (
	"context"
	"runtime"
	"syscall"
	"unsafe"
)

type trayWindowClass struct {
	Size, Style                        uint32
	Procedure                          uintptr
	ClassExtra, WindowExtra            int32
	Instance, Icon, Cursor, Background uintptr
	MenuName, ClassName                *uint16
	SmallIcon                          uintptr
}
type trayNotification struct {
	Size                uint32
	Window              uintptr
	ID, Flags, Callback uint32
	Icon                uintptr
	Tip                 [128]uint16
	State, StateMask    uint32
	Info                [256]uint16
	Version             uint32
	InfoTitle           [64]uint16
	InfoFlags           uint32
	GUID                [16]byte
	BalloonIcon         uintptr
}
type trayMessage struct {
	Window         uintptr
	Message        uint32
	WParam, LParam uintptr
	Time           uint32
	Point          [2]int32
	Private        uint32
}

func runTray(ctx context.Context, address string, tracking func() string) {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	api := syscall.NewLazyDLL("user32.dll")
	shell := syscall.NewLazyDLL("shell32.dll")
	kernel := syscall.NewLazyDLL("kernel32.dll")
	instance, _, _ := kernel.NewProc("GetModuleHandleW").Call(0)
	icon, _, _ := api.NewProc("LoadIconW").Call(0, 32512)
	name, _ := syscall.UTF16PtrFromString("ActivityPlatformTray")
	var notify trayNotification
	notify.Size = uint32(unsafe.Sizeof(notify))
	notify.ID = 1
	notify.Flags = 1 | 2 | 4
	notify.Callback = 0x8001
	notify.Icon = icon
	setTip := func() {
		text := "Activity: " + tracking()
		notify.Tip = [128]uint16{}
		value, _ := syscall.UTF16FromString(text)
		copy(notify.Tip[:], value)
	}
	callback := syscall.NewCallback(func(hwnd uintptr, message uint32, wparam, lparam uintptr) uintptr {
		switch message {
		case 0x8001:
			if lparam == 0x202 || lparam == 0x205 || lparam == 0x203 {
				go openBrowser(address)
			}
			return 0
		case 0x113:
			setTip()
			shell.NewProc("Shell_NotifyIconW").Call(1, uintptr(unsafe.Pointer(&notify)))
			return 0
		case 0x10:
			api.NewProc("DestroyWindow").Call(hwnd)
			return 0
		case 0x2:
			shell.NewProc("Shell_NotifyIconW").Call(2, uintptr(unsafe.Pointer(&notify)))
			api.NewProc("PostQuitMessage").Call(0)
			return 0
		}
		result, _, _ := api.NewProc("DefWindowProcW").Call(hwnd, uintptr(message), wparam, lparam)
		return result
	})
	class := trayWindowClass{Instance: instance, Procedure: callback, ClassName: name}
	class.Size = uint32(unsafe.Sizeof(class))
	if result, _, _ := api.NewProc("RegisterClassExW").Call(uintptr(unsafe.Pointer(&class))); result == 0 {
		return
	}
	hwnd, _, _ := api.NewProc("CreateWindowExW").Call(0, uintptr(unsafe.Pointer(name)), uintptr(unsafe.Pointer(name)), 0, 0, 0, 0, 0, 0, 0, instance, 0)
	if hwnd == 0 {
		return
	}
	notify.Window = hwnd
	setTip()
	shell.NewProc("Shell_NotifyIconW").Call(0, uintptr(unsafe.Pointer(&notify)))
	api.NewProc("SetTimer").Call(hwnd, 1, 3000, 0)
	go func() { <-ctx.Done(); api.NewProc("PostMessageW").Call(hwnd, 0x10, 0, 0) }()
	var message trayMessage
	for {
		result, _, _ := api.NewProc("GetMessageW").Call(uintptr(unsafe.Pointer(&message)), 0, 0, 0)
		if int32(result) <= 0 {
			break
		}
		api.NewProc("TranslateMessage").Call(uintptr(unsafe.Pointer(&message)))
		api.NewProc("DispatchMessageW").Call(uintptr(unsafe.Pointer(&message)))
	}
}
