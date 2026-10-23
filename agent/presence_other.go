//go:build !windows

package main

func readPresence() Presence { return classifyPresence(0, false) }
