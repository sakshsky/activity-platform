# Optional recording, activity reviews and screenshot deletion

## Collection boundaries

- No keystroke-content log or clipboard scraping.
- No separate mouse-coordinate or click-history log. Last-input timestamps support idle detection; optional timing review uses aggregate intervals.
- No file-system scan or automatic upload of personal documents.
- No webcam or microphone recording.
- No extraction of passwords from browsers or password managers.
- No dedicated government-ID or payroll fields; free-text fields should not be used for these.
- No background-process inventory; activity samples identify the foreground application and window.

**These are collection-method boundaries, not a promise that captured content is non-sensitive.** Screenshots, optional silent video, window titles and optional URLs can contain personal information, visible credentials or document text. Automated redaction and sensitive-app exclusions are not implemented. Sign out or quit the agent to stop capture before displaying sensitive content. Previously collected data remains subject to retention and deletion rules.

See [Trust & deployment facts](COMPLIANCE.md) for role access, data locations and encryption limits.

## Upgrade

Back up the database and private storage. Preserve .env, APP_KEY and existing employee data. Copy server code and compiled UI, then run from server:

```powershell
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

Keep the queue worker and scheduler running (README). Replace the employee EXE only after the server is updated, keeping agent-config.json and data. Both optional modules default to OFF. Deletion policy is neutral. Existing policies and scores are preserved.

## Silent video clips

Install an organization-approved FFmpeg build supporting gdigrab and libx264 on Windows. Put ffmpeg.exe beside activity-agent.exe or on PATH. Install FFprobe on the server and set TRACKER_FFPROBE_BINARY to its executable path in .env (quote paths with spaces). Restart the agent and server workers after changes. The v1.5.4 Windows ZIP includes FFmpeg and publisher notices; source-only distributions exclude binaries. FFprobe is installed separately on the server; comply with the chosen build's licenses when redistributing it.

In admin Policy, enable silent clips and configure length (5–30 seconds), gap between clips (60–3600 seconds), retention (1–30 days) and quota per employee (50–10240 MB). Defaults are 10-second clips, a 300-second gap, 7 days, 1024 MB. Clips use H.264 MP4, 2 frames/second, at most 1280×720, no audio or mouse pointer overlay, maximum 20 MB. This is periodic recording, not continuous video or a webcam.

Employees must explicitly acknowledge the module notice for the current policy version. Any policy update requires acknowledgment again. Capture requires active input state and a server contact within 60 seconds; lock, idle, sign-out, disabled monitoring or lost contact stops the recording, normally detected within one second. Partial clips are discarded. The browser status and tray tooltip show recording status. Employees and authorized reviewers can play retained clips in Videos. Missing FFmpeg is shown in employee status; the server rejects videos it cannot validate with FFprobe. Review status before enabling for employees.

Uploads use the existing authenticated API and private local/FTPS vault. Quotas are checked across an employee's devices. Run retention cleanup to free storage. Old-policy optional jobs are not sent under a new acknowledgment; they expire under the offline queue retention. A failed upload remains subject to queue limits. Browser playback supports byte ranges; storage retrieval is bounded to 20 MB per request.

## Input-timing review

The optional detector is a disclosed rule, not AI or a reliable fraud detector. Every 10-minute active window must contain at least 30 observed input intervals; at least 90% must share one 200 ms bucket, with a period between 2 and 60 seconds. Windows last-input sampling occurs once per second. Only qualifying aggregate counts, bucket, timestamps and rule version are uploaded; no typed text, key codes or pointer positions are stored. Sampling misses events and legitimate repetitive work, accessibility tools and automation can match the rule.

Employees see their own evidence and can add context. Super admins review each flag as Explained / no concern or Needs discussion, recording their reason. Updates use revisions to prevent overwriting a concurrent review. Flags, status changes and deletions never automatically affect published scores, ranking, hours or employment decisions. Existing outcome scoring remains independent. Evidence follows the screenshot/activity retention setting.

## Screenshot deletion

In employee My data, use Delete screenshot, optionally add a reason, and confirm. Only the employee's own uploaded screenshots can be deleted. Local queued captures and video deletion are outside this feature. The original and thumbnail are removed from active storage; the screenshot record remains with one shared neutral organization placeholder. Each card and Image information show the exact deletion time, actor and reason. Original image dimensions and checksum remain as historical metadata, not properties of the placeholder.

Deletion first commits a hidden state and audit event, then removes files. If storage is unavailable, the original stays hidden and the UI reports pending cleanup. The queue retries; tracker:prune retries pending deletions as well. Monitor failed jobs and storage connectivity. FTPS ambiguous/missing-file responses can require administrator investigation; never treat a storage error as proof of deletion. Thumbnail jobs cannot publish a new thumbnail for a deleted screenshot.

Super admins have Deletions history with employee/date filters; reviewer accounts cannot access it. Audit events retain employee/device/capture identifiers, timestamp, optional reason and IP under the audit retention policy. Reserved flag fields remain false/null; future deletion modes are not displayed or accepted. Retention removes expired capture rows without deleting the shared placeholder. Copies already downloaded, previously delivered in-flight responses and backups are not recalled. Apply your separate backup retention procedures.

## Acceptance checks before rollout

Run Laravel tests against SQLite and an isolated MySQL test database, Go tests and vet, and the Vue production build. Never use a live database with RefreshDatabase tests. On a test workstation explicitly enable and acknowledge the modules, verify visible recording, play a real clip, lock/idle/sign out during capture and verify it stops, then disable both modules. Test your actual FTPS destination if used. Native display capture and FFmpeg installation need an interactive acceptance test on the target PCs; automated tests use fixtures/mocked inspection and do not record an employee desktop.

## v1.5.0 work-app context

Work App % derives from existing categorized activity durations. It requires no new collection or optional-module acknowledgment and never affects scores. Employees and admins see the same informational metric; published review snapshots preserve it. Unclassified time is excluded, and no categorized data produces no percentage.
