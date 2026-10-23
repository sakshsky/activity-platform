export function reviewedScore(metrics, collaboration) {
 const weighted = Math.round(metrics.completion * 100) * 40 + Math.round(metrics.quality * 100) * 30 + Math.round(metrics.deadlines * 100) * 20 + Number(collaboration) * 1000
 return Math.floor((weighted + 50) / 100) / 100
}
