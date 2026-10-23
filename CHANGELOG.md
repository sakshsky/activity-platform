# v1.5.5 — 2026-10-23

- Added super-admin System checks page for runtime, extensions, database, cache, storage, HTTPS and FFprobe readiness.
- Added employee workstation diagnostics before login and under System checks after login, including FFmpeg capabilities, connection and consent readiness.
- Explicit manual checks for worker health, remote delivery and actual recording; diagnostics never start capture or change consent.

# v1.5.4 — 2026-10-23

- Isolated agent endpoint rate limits so activity traffic does not exhaust video consent, video upload or heartbeat budgets.
- Added a regression test proving isolation while preserving the consent request limit.
- Windows video package includes the FFmpeg runtime and publisher notices; server video validation requires FFprobe.

# v1.5.3 — 2026-10-23

- Fixed first-run browser authorization: setup and status now load before employee login.
- Preserve local browser access after employee sign-out, while employee records remain protected.
- Show connection failures and a retry option instead of an indefinite connecting screen.
- Added first-run, sign-out, and browser-cookie renewal regression coverage. No server migrations.

# v1.5.2 — 2026-10-22

- Fixed employee portal refresh after saving while a background request is still running; older responses cannot overwrite the latest data.
- Added a regression test confirming session shutdown cancels an in-flight screenshot upload instead of waiting for its upload timeout.
- No database migrations, new dependencies, collection changes or scoring changes.
# v1.5.1 — 2026-10-22

- Customer-facing capture terminology, with explicit screenshots/app-activity status and unchanged collection behavior.
- Admin collection-boundaries panel and downloadable trust guide. Clarifies incidental sensitive screen content, role access, encryption limits and organization-configured external storage/email.
- Local demo instructions moved near the top of README, with correct prerequisites and isolated-demo guidance.
- Static Starter/Outcome/Full HR package comparison and support@sakshamapp.com contact, with unannounced prices and no implied billing, plan enforcement or redistribution rights.
- IT deployment checklist and updated employee documentation. No new collection, score rules, dependencies or database migrations.

# v1.5.0 — 2026-10-22

- Display-only weekly Work App %, visible to employees and admins with an informational caption and preserved in published snapshots. Unclassified time is excluded; no data is null. Outcome scores are unchanged.
- Policy idle threshold remains editable (30–600 seconds). New installations default to 120 seconds; existing values are preserved by the migration.
- Agency, devshop and office presets add suggested cohort app rules after confirmation without overwriting existing rules. Repeated application is idempotent.
- Plain-text weekly summary command, Monday 07:00 UTC schedule, dry-run and log-mailer support. Only enabled, individually opted-in super admins receive summaries; default off. No performance scores or rankings in email.
- Warning-free frontend build invocation on Windows. No new collection, AI service, third-party integration, or changes to neutral deletion and consent gates.

# v1.4.0 — 2026-10-21

- Optional silent screen-video clips, disabled by default, with explicit employee acknowledgment, visible recording state, own-record playback, retention and per-employee quotas.
- Optional aggregate input-timing flags, disabled by default. Employees see evidence and can add explanations; super admins review with revision protection. No automatic performance-score changes and no misconduct verdicts.
- Employee deletion of their own uploaded screenshots, with confirmation and optional reason. One shared organization placeholder, exact per-capture deletion metadata, and super-admin deletion history.
- Neutral deletion only. Future deletion modes are hidden. Originals and thumbnails are removed; cleanup failures keep content hidden and are retried by the queue and scheduler.
- Authenticated video range playback and screenshot deletion proxy, ownership tests, audit logs and thumbnail publication race protection.
- Requires both new migrations and updated agent. Video needs FFmpeg on Windows and FFprobe on the server; these binaries are not bundled. See OPTIONAL-MODULES.md.

# v1.3.0 — 2026-10-21

- Authenticated admin EXE/ZIP downloads with SHA-256 verification and setup instructions.
- Configurable idle threshold and employee inactivity reminders.
- Estimated categorized daily app time, exact-domain rules, explicitly applied job-family defaults, and neutral observed-window-change context.
- Agent preview/proxy lock fixes, less frequent browser reads, longer upload timeout and private local status.
- Required profile update revisions, FTPS staging/error guards, reduced overview audit noise, bounded export probe and PHP 8.4.1 requirement.
- Activity context remains separate from manager-reviewed outcome scores. No synthetic-activity verdicts.

# Changelog

## v1.2.0 — 2026-10-20

- Authenticated screenshot metadata, including server-verified dimensions/checksum and capture input state.
- Task/goal assignment, employee evidence submissions, manager quality review and revision checks.
- Weekly outcome reports, employee context notes and role-specific application categories.
- Transparent 40/30/20/10 scoring, minimum data requirements, explicit manager publication and immutable review snapshots.
- Same-role rankings with ties, peer thresholds and self-scoped employee results.
- Requires the new Laravel migration, Vue build and updated employee EXE. See README for upgrade steps and retention boundaries.

## v1.1.0 — 2026-10-20

- Windows idle/lock detection with a five-minute inactivity threshold.
- Screenshots and screenshot uploads pause while inactive.
- Separate active, idle and legacy daily time totals.
- Requires server migration and updated Windows agent.

## 1.0.0 — 2026-10-20

- Laravel/MySQL API and Vue administrator dashboard with employee, device, policy and audit controls.
- Go Windows agent with guided connection setup, desktop shortcut, visible monitoring status and Windows-encrypted login session.
- Random screenshot capture, foreground application/window sampling and optional supported browser URL collection.
- Persistent upload queue, retry handling and private local/FTPS server storage.
- Employee dashboard with latest screenshot preview and upload status.
- Login/session history, daily server-confirmed tracked time, reporting timezones and weekly chart; overlapping devices count once.
- Personal, address, emergency-contact and education records; encrypted personal-details section.
- Admin-managed employment details and HR review with revision checks.
- Employee leave requests, cancellation and administrator decisions.
- Employee access to their own retained server screenshots, application activity and collected URLs.
- Regression fixes for MySQL policy seeding, token replacement, upload handling and thumbnail processing.

### Release scope

Windows x64 portable application, unsigned. Requires a configured Laravel server. Time history starts after installation/upgrade and measures confirmed monitoring time, not payroll or productivity. No identity documents, government ID numbers, payroll, leave balances or work-hours schedules. See README and RELEASE_GUIDE for setup, upgrade and validation limits.


