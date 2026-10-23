package main

import (
	"syscall"
	"unsafe"
)

var getLastInputInfo = user32.NewProc("GetLastInputInfo")
var getTickCount = syscall.NewLazyDLL("kernel32.dll").NewProc("GetTickCount")
var openInputDesktop = user32.NewProc("OpenInputDesktop")
var switchDesktop = user32.NewProc("SwitchDesktop")
var closeDesktop = user32.NewProc("CloseDesktop")
var wtsQuery = syscall.NewLazyDLL("wtsapi32.dll").NewProc("WTSQuerySessionInformationW")
var wtsFree = syscall.NewLazyDLL("wtsapi32.dll").NewProc("WTSFreeMemory")

// Only the last input timestamp is read; no keys or mouse contents are collected.
func readPresence() Presence {
	var buffer *uint32
	var size uint32
	okSession, _, _ := wtsQuery.Call(0, 0xffffffff, 8, uintptr(unsafe.Pointer(&buffer)), uintptr(unsafe.Pointer(&size)))
	if okSession == 0 || buffer == nil {
		return classifyPresence(0, false)
	}
	connected := size >= 4 && *buffer == 0
	wtsFree.Call(uintptr(unsafe.Pointer(buffer)))
	if !connected {
		return classifyPresence(0, false)
	}
	desktop, _, _ := openInputDesktop.Call(0, 0, 0x0100)
	if desktop == 0 {
		return classifyPresence(0, false)
	}
	defer closeDesktop.Call(desktop)
	visible, _, _ := switchDesktop.Call(desktop)
	if visible == 0 {
		return classifyPresence(0, false)
	}
	info := struct {
		Size uint32
		Tick uint32
	}{Size: 8}
	ok, _, _ := getLastInputInfo.Call(uintptr(unsafe.Pointer(&info)))
	if ok == 0 {
		return classifyPresence(0, false)
	}
	tick, _, _ := getTickCount.Call()
	result := classifyPresence((uint32(tick)-info.Tick)/1000, true)
	result.InputTick = info.Tick
	return result
}
