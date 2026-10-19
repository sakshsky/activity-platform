# Review resolution

This pass addresses the attached review's bug findings. The 80 suggested enhancements are a future roadmap, not features implemented by this patch.

## Changes

- **Queue recovery (#5):** invalid manifests and missing payload records move to `data/quarantine`; healthy records continue loading. Payload paths are derived from validated filenames, never corrupt JSON. Quarantined files count toward the queue's storage budget and expire under offline retention.
- **Permanent failures (#4):** HTTP 400, 413, 415 and 422 mark records as failed. Other failures stop automatic retries after 100 attempts. Failed manifests and payloads remain in the bounded queue until retention expires. The local UI shows their count. The manifests' `failure` fields and diagnostic logs explain the state; no automatic replay of failed records is provided.
- **Scheduling (#2):** explicit fixed-window advancement skips elapsed sleep windows. A fresh random target is chosen from the remaining part of the current window, never from an already elapsed part. A consumed window is not replayed. This remains one attempted capture per available window, with documented gaps for sleep, cancellation and failures.
- **Thumbnail concurrency (#3):** each attempt writes a unique file, then publishes it with a conditional `WHERE thumbnail_path IS NULL` update. A losing attempt deletes only its own file. Retention reloads the screenshot under a row lock before deletion so it includes newly published thumbnails.
- **Thumbnail memory (#24):** inspect dimensions before GD decoding; inputs above 16 million pixels retain their original image without generating a thumbnail. The existing gallery fallback displays the original. Ingestion still permits multi-monitor originals up to 64 million pixels / 20 MB.
- **Literal search (#10):** application/title filters escape LIKE wildcards with an explicit escape character, using bound parameters on both MySQL and SQLite.
- **Intranet URLs (#11):** explicitly schemed single-label HTTP(S) hosts are accepted; ambiguous bare search words remain rejected.
- **Policy reads (#14–17):** hydrate defaults only on creation and reuse one policy snapshot per operation. No persistent cache was added, so administration changes do not wait for cache expiry.
- **Mixed DPI (#18):** request per-monitor V2 awareness for the capture process/thread, with a legacy fallback. Live mixed-DPI display testing remains necessary.
- **PNG validation:** explicitly check the PNG signature in addition to file type and dimension validation.
- **Queue accounting (#20):** document and reserve manifest growth space for retry/failure metadata.

## Findings that did not reproduce

- **Windows replacement (#1, #6):** the installed Go standard library implements `os.Rename` with `MoveFileEx(..., MOVEFILE_REPLACE_EXISTING)`. A Windows regression test overwrites the same file three times and verifies its contents. Do not delete the old file before replacement: that would introduce an unnecessary data-loss window. This confirms replacement behavior, not a general Windows filesystem atomicity guarantee. `loadIdentity` also propagates write errors; it does not silently ignore them.
- **Token expiry (#12):** installed Sanctum checks `expires_at` independently of its global `expiration` option in `vendor/laravel/sanctum/src/Guard.php::isValidAccessToken`. A regression test sets the global option to null and verifies that an expired device token receives HTTP 401.
- **FTPS deletion (#9):** curl supports `--quote -DELE name` after a successful transfer. Listing the parent directory then deleting a relative filename is valid. See [curl --quote documentation](https://curl.se/docs/manpage.html#-Q). Real FTPS endpoint integration is still unverified; remote deletion failures remain visible and retain the database record for retry.
- **Capture cancellation (#7):** both cancellation branches already remove the staged payload before returning.
- **Filename slicing (#19), random seeding (#21), auth session regeneration (#13), and retention test (#23):** the review itself correctly concludes these are not bugs.
- **Original-app test/global settings references (#22, #28, #29):** do not apply to this project.
- **Old timestamps (#26):** retention is based on capture time, so older captures expire sooner, not later. Already-expired events are acknowledged and discarded.
- **Enrollment attribution (#27):** employee ID and IP were already recorded in metadata. The audit actor remains system for that pre-session operation.
- **Host matching (#30):** exact loopback Host matching is intentional; use the address printed by the agent.

## Deliberate limits and deferred work

Retention of screenshots, activity and audit is independent (#8). A failed remote image deletion does not block deletion of expired unrelated activity/audit. A database rollback cannot restore files already deleted from FTPS; partial storage cleanup may require operator reconciliation. Errors return command failure and keep the screenshot record.

CSV exports remain streamed with a preflight count and a 100,000-row / 31-day limit (#25). Cursor pagination, full-text search, precomputed counters and other scale optimizations need workload measurements. Changing pagination semantics or silently truncating CSV output was not part of this bug pass.

This is a **single-organization application with roles**, not a multi-tenant platform. Dashboard authentication uses Laravel web sessions and CSRF; device authentication uses Sanctum tokens. Audit logging covers the implemented review/management actions, not literally every database read.

No new legal compliance certification, DPA, geofence, off-hours monitoring, blur filter, SSO, metrics platform, third-party telemetry service or device fleet installer was added. Existing MySQL/Docker, FTPS and interactive native-Windows validation limitations still apply.

## Verification

- Go: 12 passing tests, including Windows replacement, retry persistence, corrupted queue isolation, terminal failures, window advancement and intranet URL sanitization.
- Laravel: 17 passing tests / 73 assertions, including server-enforced token expiry, literal search, interleaved thumbnail publication and pre-decode dimension limits.
- Frontend: 2 utility tests; production bundle rebuilt and embedded in the rebuilt Windows executable.
- Go vet and PHP Pint run before packaging.

The concurrency test deliberately interleaves two thumbnail attempts; it does not replace a multi-process MySQL load test. FTPS syntax validation is based on curl's contract, not a live FTPS integration test.
