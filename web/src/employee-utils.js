export function duration(seconds) {
  const total = Math.max(0, Math.floor(Number(seconds) || 0))
  return `${Math.floor(total / 3600)}h ${String(Math.floor(total % 3600 / 60)).padStart(2, '0')}m`
}
