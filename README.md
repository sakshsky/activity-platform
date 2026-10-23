# Activity Platform

Release **v1.5.5**. For GitHub upload and release instructions, see [RELEASE_GUIDE.md](RELEASE_GUIDE.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

Two applications: a Vue 3 admin dashboard backed by Laravel 13 and MySQL, and a Go Windows desktop agent with an embedded Vue status/login screen.

## Why this platform is different

- **Visible capture:** employees can check collection status in the agent UI and Windows tray, and sign out or quit to stop it.
- **Optional modules:** video and timing review are off by default and require current, explicit acknowledgment.
- **Activity is context:** idle time, screenshots and Work App % never change an outcome score.
- **Employee access:** employees can review their own retained capture records and explain activity flags.
- **Neutral deletion:** employees can delete their uploaded screenshots with no automatic flags or penalties.
- **Organization-controlled hosting:** choose your server, storage and optional mail/FTPS destinations. No mandatory vendor cloud or built-in AI analysis service.

Read [Trust & deployment facts](COMPLIANCE.md) and the [IT deployment checklist](deploy/CHECKLIST.md). These describe implementation, not legal certification. The [package comparison](marketing/pricing.html) has no billing or enforced plan restrictions.

## What we don't collect (by design)

- No keystroke-content log or clipboard scraping.
- No separate mouse-coordinate or click-history log. Last-input timestamps support idle detection; optional timing review uses aggregate intervals.
- No file-system scan or automatic upload of personal documents.
- No webcam or microphone recording.
- No extraction of passwords from browsers or password managers.
- No dedicated government-ID or payroll fields; free-text fields should not be used for these.
- No background-process inventory; activity samples identify the foreground application and window.

**These are collection-method boundaries, not a promise that captured content is non-sensitive.** Screenshots, optional silent video, window titles and optional URLs can contain personal information, visible credentials or document text. Automated redaction and sensitive-app exclusions are not implemented. Sign out or quit the agent to stop capture before displaying sensitive content. Previously collected data remains subject to retention and deletion rules.

Account authentication, HR records and audit logs are also stored. See the trust guide for the complete access, encryption and retention boundaries.

## Try it locally — no MySQL or remote hosting required

Use a **fresh extracted copy** for the demo. It runs a local PHP server; setup time depends on installed prerequisites and dependency downloads. Never run DemoSeeder against employee or production data. It creates demo accounts and synthetic screenshots, not a reset or a switch to real data.

Prerequisites: PHP **8.4.1+**, Composer 2, and PHP extensions PDO SQLite, mbstring, fileinfo, OpenSSL, cURL, GD, ZIP, Intl and FTP (per the lockfile). Compiled Vue assets are included in the source ZIP; Node is needed only to rebuild them. From the fresh copy's server folder:

```powershell
composer install
Copy-Item .env.example .env
New-Item database/database.sqlite -ItemType File
```

Edit this demo's .env: set DB_CONNECTION=sqlite, remove DB_DATABASE (Laravel will use database/database.sqlite), keep APP_ENV=local and TRACKER_STORAGE=local, and use APP_URL=http://127.0.0.1:8000. Then:

```powershell
php artisan config:clear
php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder
php artisan serve --host=127.0.0.1 --port=8000
```

For an already configured **disposable demo only**, PowerShell 7 supports the seed shorthand: `php artisan migrate --seed && php artisan db:seed --class=DemoSeeder`. Do not overwrite an existing .env or regenerate an existing APP_KEY. To review without collecting any desktop data, use just the website; the desktop agent is not required.

Open http://127.0.0.1:8000. Demo accounts: **admin@example.test**, **reviewer@example.test**, **employee@example.test**; password for all three: **LocalDemo!2026**. These credentials are for isolated local demos only. Synthetic screenshots are clearly labeled; the demo does not populate every HR or performance feature. Create real work samples separately if evaluating those workflows.

During extended demo use, run `php artisan queue:work --tries=3 --timeout=120` and `php artisan schedule:work` in separate terminals. Production installation instructions follow; do not deploy demo credentials publicly.

## Source package and project layout

The GitHub source ZIP includes all application source, dependency lockfiles, tests, deployment files and compiled Vue assets. It excludes installed dependencies, executables, credentials, runtime databases, employee screenshots and logs. Run the commands below to install dependencies and build the Windows executable. Use Node 22.12+ and PowerShell 7 for the supplied build/packaging scripts.

| Folder | Contents |
| --- | --- |
| `server/` | Laravel API, administrator authentication, migrations, jobs and tests |
| `web/` | Vue administrator dashboard and agent interface |
| `agent/` | Go Windows collector, queue, embedded frontend and tests |
| `deploy/` | Docker, PHP and Nginx configuration |
| `scripts/` | Frontend build, Windows agent build and release packaging |

Choose **Herd** below for Windows development, **Try it locally** for SQLite, or **Docker / MySQL** for containers. Only `web/` needs npm installation; the Laravel skeleton's `server/package.json` is not the dashboard build.

## Windows installation with Laravel Herd

1. Extract the ZIP, preserving the `activity-platform` directory. Install PHP 8.4.1 in Herd, Node 22.12+, and MySQL (Herd Pro Services or a separate MySQL server). To build the agent, install Go 1.22+ too.
2. In PowerShell, enter the extracted `server` directory and link it:

```powershell
cd C:\Users\YOUR_USER\Herd\activity-platform\server
herd link activity
herd isolate 8.4 --site=activity
herd secure activity
herd composer install
if (!(Test-Path .env)) { Copy-Item .env.example .env }
```

3. Create a MySQL database named `activity_platform`. Edit `server/.env` with your actual database credentials and service port:

```dotenv
APP_ENV=local
APP_URL=https://activity.test
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=activity_platform
DB_USERNAME=your_mysql_user
DB_PASSWORD="your_mysql_password"
SESSION_SECURE_COOKIE=true
TRACKER_STORAGE=local
```

4. Initialize this new installation. Generate the application key only when APP_KEY is empty; preserve it on upgrades.

```powershell
herd php artisan config:clear
herd php artisan key:generate
herd php artisan migrate --seed
herd php artisan tracker:create-admin admin@example.com
```

5. Open https://activity.test and log in with the administrator account you just created. Run these in two separate terminals in `server/` and keep them running:

```powershell
herd php artisan queue:work --tries=3 --timeout=120
```

```powershell
herd php artisan schedule:work
```

Herd serves the website; `artisan serve` is unnecessary with this option. The release includes built Vue assets. To rebuild the frontend and Windows executable from source, run from the project root:

```powershell
cd web
npm ci
cd ..
node scripts/build-web.mjs
./scripts/build-agent.ps1
```

Copy `agent/agent-config.example.json` to `agent/agent-config.json`, set `server_url` to `https://activity.test`, and start the resulting `agent/activity-agent.exe`. Create an employee in the dashboard and log in through the agent. Capture begins after employee login and notice acceptance when policy permits it. The local `.test` address is only for this computer; other machines require a reachable HTTPS deployment.

PHP needs GD for thumbnails and PDO MySQL for MySQL. Set `upload_max_filesize=20M`, `post_max_size=24M`, `memory_limit=512M`, and ensure Nginx permits 24 MB requests. Restart affected Herd services after PHP configuration changes. If a certificate error occurs, repair Herd's local certificate trust rather than disabling TLS verification.

References: [Herd site linking](https://herd.laravel.com/docs/windows/getting-started/sites), [PHP isolation](https://herd.laravel.com/docs/windows/technology/php-versions), [HTTPS](https://herd.laravel.com/docs/windows/sites/securing-sites).

## Implemented

- Real server authentication; employee enrollment; administrator and reviewer roles; disabled accounts and revoked device tokens.
- Live local clock, visible capture notice, Windows tray icon and console status.
- One screenshot at a random offset in each 120-second period by default. Captures the combined Windows desktop across monitors. Administrators can change the period.
- Foreground application/process name and window title sampling. Optional browser address capture through Windows UI Automation; URL credentials, query strings and fragments are removed.
- Private screenshot gallery with thumbnails, full-size viewing, employee/device/date/application filters and pagination.
- Searchable activity records and CSV export with spreadsheet formula neutralization.
- Device enrollment, persistent UUID, heartbeats, online status, administrator pause/resume and disable controls.
- Central policy settings for capture interval, application sampling, URL collection, notice, retention and offline queue size.
- HTTPS API ingestion with device-scoped tokens, duplicate detection, explicit upload acknowledgments and validation.
- Private server storage or certificate-verified FTPS. The admin Policy page has a storage write/read/delete verification button. Destination credentials are set in the **server environment**, not distributed to employees or editable in the browser.
- Persistent local outbox, retry delays, restart recovery, per-user ownership, disk limits, expiry and local diagnostic logs. Payloads leave the queue only after acknowledgment or configured retention expiry.
- Windows DPAPI protection for saved login tokens; session login and CSRF protection for the dashboard; role checks and audit records for sensitive access.
- Queued thumbnail generation and scheduled retention cleanup, plus MySQL migrations, Docker deployment files, tests and a Windows executable.

## MySQL installation

Use `server/.env.example`, create a MySQL database and restricted application user, then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to your HTTPS origin and `SESSION_SECURE_COOKIE=true`.

```text
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --seed --force
php artisan tracker:create-admin admin@your-company.com
php artisan config:cache
```

The create-admin command prompts privately for a password of at least 12 characters. Add employee and reviewer accounts through Employees in the dashboard. Set the web document root to `server/public`, give the PHP runtime write access to `storage` and `bootstrap/cache`, and keep the queue worker and scheduler running. Configure PHP `upload_max_filesize=20M`, `post_max_size=24M`, `memory_limit=512M` and your proxy's request-body limit accordingly. Do not expose the private storage directory with a public symlink.

## Docker / MySQL

Docker Desktop or Docker Engine with Compose is required. Copy the root `.env.example` to root `.env`, replace both database passwords, and generate a stable Laravel key:

```text
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show
```

Paste the resulting `base64:...` into root `.env` as `APP_KEY`. Then:

```text
docker compose up -d db app
docker compose exec app php artisan migrate --seed --force
docker compose exec app php artisan tracker:create-admin admin@your-company.com
docker compose up -d web worker scheduler
```

Open http://127.0.0.1:8080. The port is bound to loopback. For remote agents, put an HTTPS reverse proxy in front of it, use the HTTPS `APP_URL`, and set `SESSION_SECURE_COOKIE=true`. Back up the MySQL volume, private-data volume, environment credentials and APP_KEY. Do not regenerate APP_KEY on an existing installation. Deployments must preserve storage permissions for `www-data`.

## Run the Windows agent

1. Build `agent/activity-agent.exe` using the build commands below if using the source-only ZIP. Copy it and `agent/agent-config.example.json` into a writable folder on the employee computer.
2. Rename the configuration to `agent-config.json`. Set `server_url` to your HTTPS server origin (HTTP is accepted only for loopback IPs during development). Set `device_name` or leave it empty to use the Windows hostname.
3. Start `activity-agent.exe`. It opens http://127.0.0.1:8765/agent. Read the capture notice and sign in using an employee account.
4. Keep the agent running in the interactive Windows session. The administrator controls policy and device pause. Closing the browser tab does not stop the agent; exiting the console/process or signing out does.

`data/payloads` holds queued PNGs, `data/outbox` holds their manifests and activity events, and `data/logs` holds diagnostic logs. Successful upload removes queued files; retained images live on the server. Token storage is protected by the current Windows account. If Windows DPAPI is unavailable, the agent can run with an in-memory session and requires login after restart. Use Windows account and folder permissions to protect local screenshot files.

Build after changing frontend source:

```powershell
node scripts/build-web.mjs
./scripts/build-agent.ps1
```

The executable embeds the Vue build. Rebuilding Vue alone does not update an existing executable. The binary is unsigned. Automatic startup, MSI installation and tamper-resistant Windows service management are not included.

## FTPS storage

Default `TRACKER_STORAGE=local` writes to private Laravel storage. To use FTPS, set these on the Laravel server and restart workers / refresh cached configuration:

```dotenv
TRACKER_STORAGE=ftps
TRACKER_FTPS_URL=ftp://files.example.com/tracker/
TRACKER_FTPS_USERNAME=your-service-account
TRACKER_FTPS_PASSWORD=your-secret
TRACKER_CURL_BINARY=curl
```

`ftp://` uses explicit TLS (`--ssl-reqd`); `ftps://` uses implicit TLS. Certificates must be trusted. The service account needs create, read, write, list and delete permissions. Use **Policy → Verify storage connection** before enabling capture. Failed FTPS writes are not acknowledged, so the agent retains the upload for retry. The agent always sends to Laravel's API, which handles the FTPS destination. Arbitrary third-party upload APIs and plaintext FTP are not supported.

Existing FTPS records refer to the configured destination. Do not change its host/base folder while retained files remain there without migrating those files. Server credentials must never be included in a source archive or employee config.

## Validation and known limits

Verified in this workspace: Laravel feature suite (22 tests, 88 assertions on SQLite and MySQL), Go queue/client tests (14 tests), Go vet/build, Vue production build and frontend utility tests (2). Browser checks use the local SQLite demonstration. Run tests yourself with `php artisan test`, `go test ./...`, `go vet ./...` and `npm test` in the appropriate directories.

MySQL feature tests now pass in an isolated database. Docker deployment and a real FTPS endpoint have **not** been exercised in this environment. Native screen capture, foreground detection, tray behavior and DPAPI need an interactive Windows smoke test; sandboxed automation cannot validate those desktop integrations fully. Browser URL detection is best effort and depends on browser accessibility support; it is off by default. Secure/locked desktops and protected windows may not be capturable. This is foreground sampling, not a list of all running apps, keystroke logging or a productivity score.

Screenshots occur once per interval while the agent is awake, signed in and unpaused; sleep, failed captures, a full queue or network policy changes can create gaps. Administrator revocation takes effect when the agent next contacts the server (normally within 30 seconds); offline agents follow their last policy until token expiry. The tray does not prevent users from terminating their own process. Retention is an application setting, not a compliance certification.

The repository includes working application source and deployment configuration. Production operation still requires your server, TLS, database credentials, storage configuration and Windows rollout.

## Review fixes

See [REVIEW-RESOLUTION.md](REVIEW-RESOLUTION.md) for verified findings, regression tests and deferred suggestions. Invalid queue records are quarantined. Permanently rejected uploads and uploads failing 100 attempts stop retrying but remain on disk within the queue budget until retention expiry. Large originals above 16 million pixels use the gallery original-image fallback instead of a generated thumbnail.

## Publish the source on GitHub

Extract the GitHub source ZIP and open a terminal in its `activity-platform` directory. Create an empty repository in your GitHub account, then run:

```powershell
git init
git add .
git status --short
git commit -m "Initial Activity Platform source release"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
git push -u origin main
```

Replace the example repository URL with your own. Review the staged files before committing. The included `.gitignore` excludes `.env` files, private keys, local data, dependencies and executables. Commit `.env.example`, `composer.lock` and `web/package-lock.json`. Do not copy your production environment or employee records into the repository.

No project-wide license has been selected for this application. Choose your intended license and add a root `LICENSE` before inviting public reuse; dependency licenses and Laravel skeleton metadata do not automatically license the entire project.

To generate a new source-only ZIP from PowerShell 7:

```powershell
./scripts/package.ps1 -SourceOnly
```

To package a built distribution including the executable, first build the frontend and agent, then run `./scripts/package.ps1` without the switch. Upload binaries as GitHub Release assets rather than committing them. The packaging command prints the archive's SHA-256 checksum. This package does not create or publish a GitHub repository automatically.

## Common installation problems

- **404 in Herd:** link the `server` directory, not the project root or `web` directory.
- **Database connection refused / missing driver:** start MySQL, use its actual port and credentials, and enable PDO MySQL in the selected PHP version.
- **Missing session/cache tables:** run `artisan migrate --seed` after configuring the database.
- **419 after changing domains:** set APP_URL correctly, keep SESSION_DOMAIN unset/null, clear configuration, and sign in again on the HTTPS domain.
- **No gallery thumbnails:** ensure GD and the queue worker are running; inspect `artisan queue:failed` and `server/storage/logs/laravel.log`.
- **Frontend changes are missing:** run `node scripts/build-web.mjs`; rebuild the Go executable to update its embedded UI.
- **Agent cannot connect:** verify `server_url`, HTTPS trust and server availability. The localhost agent page and the Laravel server are different addresses.
- **Screenshots missing:** check employee login, administrator policy/device pause, queue status and the interactive Windows session. See known limits above.

## MySQL seeding repair and October review

If initial seeding fails with ModelNotFoundException for MonitoringPolicy, use this updated model, which declares its manually assigned primary key as non-incrementing. Run `php artisan config:clear` and `php artisan db:seed` from the Laravel root; do not reset the database. See [PROJECT-REVIEW-2026-10-19.md](PROJECT-REVIEW-2026-10-19.md) for the full findings and verification. Database queue retry_after now defaults to 180 seconds; if DB_QUEUE_RETRY_AFTER is set in your environment, keep it greater than the worker timeout (120 seconds in this guide).
## Employee portal update (20 October 2026)

The Windows agent now includes a responsive employee dashboard, screenshot-upload preview, successful login/session history, 30-day daily tracked-time totals, a seven-day chart, profile editing (phone, location, reporting timezone), and leave requests. Employees can request annual, sick, personal or unpaid leave and cancel pending requests. Super admins review requests under **Leave requests**, with approval/rejection notes and audit entries. Employees see only their own records. Account names and email addresses remain admin-managed.

### Upgrade an existing Herd installation

Back up your application and database first. The new EXE requires the updated server for employee records.

1. From this release's `server` folder, merge **app**, **routes**, **database/migrations**, and **public/ui** into your existing Laravel project (for example `C:\Users\YOUR_USER\Herd\ourteam`). Replace matching application files; preserve `.env`, `storage`, existing databases, and installed dependencies. If you have customized these files, merge the changes rather than overwriting your customizations. No new Composer dependencies are required for this update.
2. In a terminal in your Laravel project, run:

   ```powershell
   php artisan optimize:clear
   php artisan migrate
   php artisan queue:restart
   ```

   The migration adds employee session, work interval, profile and leave-request tables. Do not run `migrate:fresh`; it deletes existing data. Keep your queue worker and scheduler running as before.
3. Close the existing employee agent. Replace its application files from the new `agent` folder, retaining `agent-config.json` and `data`. Re-run **Setup Desktop Shortcut.cmd** if the folder location changes.
4. Start **Activity Tracker**. On a new installation, enter the server URL and device name in the window, select **Save & connect**, then sign in. Existing valid Windows-encrypted sessions resume automatically.
5. Wait for a heartbeat (normally 30 seconds). Check **My time**, **Login history**, **My profile**, and **Leave requests**. Administrators see **Leave requests** after refreshing the Laravel dashboard.

### What tracked hours mean

Hours are derived from server timestamps between consecutive eligible heartbeats, normally 30 seconds apart. A gap over 60 seconds is discarded; there is no offline time backfill. Pauses and disabled capture do not accrue time. Simultaneous intervals on multiple devices are merged, and each day is split using the employee's saved reporting timezone (including daylight-saving boundaries). New agents classify active/idle time; older records remain legacy. Totals are not a productivity measure or payroll calculation. Brief gaps within heartbeat resolution cannot be distinguished from normal polling. The newest unconfirmed interval is not included.

History starts after this upgrade; old login times and hours are not invented or reconstructed. An existing token first seen after migration is labelled as a restored session. Closing/crashing the agent without signing out shows its last confirmed contact, not an invented logout time. The login-history screen lists successful sessions, not failed attempts. Personal contact details and HR requests are stored in MySQL, not in agent JSON files. The agent keeps the session token encrypted with Windows DPAPI and does not save passwords. HR records are not currently automatically pruned by screenshot retention; choose an HR retention policy before long-term rollout.

Leave requests cover inclusive calendar dates. Overlapping pending/approved requests are rejected. This initial HR module does not implement leave allowances, paid-day calculations, holiday calendars, payroll, attendance corrections, shifts or approval hierarchies. Approved leave does not automatically pause capture.

### Source and builds

The full release includes Go source and the rebuilt `agent/activity-agent.exe`, Vue source in `web`, Laravel source in `server`, lockfiles, tests and build scripts. Runtime secrets, employee data and dependency folders are excluded. To rebuild: install the dependencies described above, run `node scripts/build-web.mjs`, then `./scripts/build-agent.ps1`. The agent EXE embeds the compiled Vue UI; rebuild the EXE after changing Vue files.
## Personnel profiles and My data (20 October 2026)

Deploy the latest `server/app`, `server/routes`, `server/database/migrations` and `server/public/ui` and run `php artisan optimize:clear`, `php artisan migrate`, then `php artisan queue:restart`. Replace the employee agent with the EXE from this release while preserving its configuration and data folder. The `expand_employee_profiles` migration adds fields without resetting existing records. This release includes all earlier employee-portal migrations too.

**My profile** now includes legal/preferred names, birth date, optional gender, nationality, personal email, alternate phone, current and permanent addresses, emergency contacts, education and professional skills. Existing phone, location and reporting-timezone preferences remain. These personal fields are optional. **Personnel** in the super-admin dashboard displays these details and allows admins to manage employee code, department, title, manager, employment type/status, joining/probation dates, office and work mode. Employment fields are read-only for employees. Changing employment status here does not enable/disable login; use account controls for access changes.

HR can mark a record reviewed. Employee edits clear that status, and revision checks reject stale edits/reviews. This is a personnel-record review, not identity verification or regulated KYC. Government ID numbers, bank details and identity-document uploads are not included.

The new personal-details section is encrypted in the database using Laravel's application key. **Preserve and securely back up the existing `.env` and `APP_KEY`; do not regenerate the key during upgrades.** Existing basic contact columns and employment fields are not encrypted by this new section's encryption. Profile reads/updates and review actions are audited without copying personal field values into audit entries.

**My data** lets employees browse their own retained server screenshots, open full images, and review application names, window titles and collected URLs across their devices. Capture and receipt timestamps, pagination and date filters are included. Filters use the reporting timezone; displayed timestamps use the browser timezone. Server-side ownership checks apply to both lists and image downloads. Device tokens stay in the native agent, not browser JavaScript. Pending local uploads only appear here once received by the server; expired/deleted records cannot be displayed. URLs appear only where collection was enabled and the browser address could be read.
## Active and idle time (v1.1.0)

The Windows agent reads the last keyboard/mouse input timestamp, never typed text. After the configured idle threshold without input, or when the input desktop is locked/unavailable, it stops taking screenshots and starting screenshot uploads. Previously queued screenshots taken while active wait until activity resumes. Application samples also pause. Policy → Idle threshold (seconds) accepts 30–600 seconds. New installations default to 120; existing settings are preserved. Reading and meetings may appear idle.

Daily reports separate active, idle and legacy time. Heartbeats arrive about every 30 seconds; only consecutive matching states within 60 seconds count. State-transition intervals, locked time and longer offline gaps are excluded. Older clients and historical records remain labeled legacy, not retroactively converted to active hours. Active time takes priority over simultaneous idle records from another device. Input detection is an estimate, not proof of productive work; reading and meetings may appear idle.

Upgrade both server and agent. Run `php artisan migrate --force` on the server and rebuild the Vue assets. Keep the existing application key, database, private storage, agent configuration and data. Existing v1.0.0 agents do not gain idle detection until replaced.


## Goals, weekly reviews and screenshot metadata (v1.2.0)

**Upgrade:** deploy the updated Laravel code and compiled `server/public/ui` directory, preserve `.env`, APP_KEY and private storage, then run `php artisan migrate --force` and `php artisan optimize:clear`. Restart queue workers after an upgrade. Replace the employee EXE after deploying the server. Previous agents do not contain the Work & goals screen or capture-state metadata. This source distribution includes current compiled Vue assets; when modifying Vue, rebuild using `node scripts/build-web.mjs` and rebuild the Go EXE.

- **Administrator → Performance:** select an employee and a Monday reporting date. Assign tasks or goals with success criteria, a due date (end of day UTC) and 1–10 scope points. Due dates cannot be backdated. Employees submit evidence; administrators accept with a quality rating, request changes, or cancel with a reason. Accepted/cancelled tasks are final. Task revisions prevent stale updates.
- **Employee → Work & goals:** view work due in a selected week or the earliest 50 open items across all weeks, submit results, and record meeting/research/offline/context notes from the last 90 days. Select a due week to see other tasks. Notes are explicitly self-reported and never add to measured hours. Weekly views support up to 100 tasks and 100 notes per employee.
- **Weekly reports:** show due tasks, accepted outcomes, active/idle/legacy time, employee notes and the top 20 retained foreground applications by sample count. Weeks are Monday–Sunday UTC, independent of the employee attendance timezone. These reports are available on demand. Optional weekly email summaries are described below. App sample counts are not durations.
- **Scores:** completion 40%, quality 30%, deadlines 20%, collaboration 10%. Completion = accepted task points / all non-cancelled due task points. Quality = points-weighted quality of accepted tasks. Deadline score = points of accepted tasks submitted by the due date / all non-cancelled due task points. The latest submission leading to acceptance is used, so manager approval delays do not penalize timeliness. Collaboration is a manager rating with evidence. Each component is 0–100. Input activity, idle time, screenshots and app classifications have zero scoring weight.
- **Eligibility:** the reporting week must be closed and must start no earlier than the employee account's creation date. At least three non-cancelled tasks, one accepted outcome, and HR department/job title are required. Otherwise the UI displays insufficient data. No missing score is silently treated as zero.
- **Publication and rankings:** a super admin reviews the concrete report, adds collaboration evidence and confirms publication. A fingerprint rejects publication if the loaded evidence changed. Published reviews are immutable snapshots; later task/context changes remain visible in the live report but do not rewrite the published score. There is no automatic publication or review-amendment workflow in this release. Rankings require two published reviews in the same week, department and job title; equal scores share a rank. Employees see only their own review/rank and peer count, never other employees' reports. Only super admins see the ranking table. The snapshot preserves the role at publication. HR should use consistent task sizing and quality criteria; the score is a management aid, not a finding that someone is wasting time.
- **Role app context:** super admins classify exact application names as work related, neutral or review with employee context for the selected department/job title. These categories are visible to that employee and do not affect scores. Unknown apps stay unclassified.
- **Screenshot information:** open an uploaded image and expand Image information. Both admin and employee views show authenticated employee/device identity, capture/receipt time, app/title/available URL, dimensions, file size, MIME type, SHA-256, agent version and agent-reported input state/idle duration/threshold. Dimensions, bytes and checksum are calculated by the server. Identity comes from the enrolled device, never a client-supplied employee ID. Metadata travels in the authenticated upload and is linked in the database; it is not an EXIF modification or a separate FTPS JSON sidecar. Older records display unavailable fields honestly.

Task/review/context records are persistent HR records and are not purged by screenshot/activity retention. Published snapshots retain their included task/context evidence and aggregate application counts even if raw activity expires. Include these tables in database backups and set an organizational HR retention process. No external AI, OCR, image analysis, keystroke recording, or automatic employment decision is involved.

## v1.3.0: outcome review with activity context

Admin **Downloads** provides the Windows EXE and a ZIP with shortcut setup, version, size, and SHA-256. Download endpoints require an enabled admin/reviewer session. Files live privately under `server/storage/app/agent-release`; they are excluded from Git/source packages. After building the agent, run `./scripts/prepare-agent-download.ps1` (or pass `-ServerPath` for an existing Laravel installation). On Linux, copy the generated release directory into Laravel storage. This is a portable, unsigned Windows application. A local Herd .test address is not automatically reachable from employee PCs.

After upgrading server code, run `php artisan migrate --force` and `php artisan db:seed --class=AppRuleSeeder --force`. This seeder inserts suggested templates only and never overwrites role rules. In Performance, select an employee with a department/job title and explicitly apply a job-family template. Browsers and communication apps are neutral by default; context categories are not judgments of employee quality. Exact domain rules override app rules only on records with an observed address. No wildcard/subdomain matching is implied. Rules may be changed to unclassified; existing published reviews stay immutable.

Existing idle thresholds, including 300 seconds, are preserved; new installations default to 120 seconds. The policy accepts 30–600 seconds. Employee inactivity reminders default to 10 minutes and can be disabled with 0. Browser URL reads run at most once per 30 seconds; intervening records have no reused URL. The agent sends bounded durations only between consecutive active samples in the same window/title. Older records retain unknown duration, rather than invented retrospective hours. Cross-device overlap counts once; conflicting categories are unclassified. This is estimated foreground application time, not verified work time or payroll.

Foreground-window changes are observed only at sample boundaries, not continuously. Long runs with no observed changes display a neutral context note. **There is no mouse-jiggler or synthetic-activity detector**: no-switch behavior cannot reliably distinguish focused work from manipulation. These metrics never affect review scores. No video, keystroke content, or new continuous input collection was added.

Profile updates now require the current revision after the first save. The local status endpoint requires the browser cookie; slow portal responses are discarded when the employee session changes. Preview and portal network writes do not hold the agent transition mutex. Screenshot uploads have a 120-second timeout. PHP 8.4.1 or newer is required by the locked dependencies. The root environment example is for production/Docker; `server/.env.example` is for local Laravel/Herd development.

Publication fingerprints identify the live evidence before publication, excluding the published wrapper; they are not a signature of the final snapshot. Published snapshots include scoring rules so historical reviews remain self-contained. Queue byte reservations can change slightly on restart as serialized retry metadata grows; the limit is approximate. Native Windows tray/lock behavior and real FTPS uploads still need environment-specific smoke testing.

This release is positioned as outcome review with transparent activity context. No pricing, subscription enforcement, or competitor-parity claim is implemented.

## v1.4 optional modules and neutral deletion

See [OPTIONAL-MODULES.md](OPTIONAL-MODULES.md) for deployment, FFmpeg/FFprobe setup, explicit employee acknowledgment, human-reviewed timing flags and screenshot deletion. Video and timing review are disabled by default. Deletion is neutral and has no score effects.

## Getting started fast: industry presets (v1.5.0)

Set an employee's department and job title in Personnel. In Performance → Application context, choose **Apply a preset for this role**, review the confirmation and apply:

- **agency:** Figma/Adobe and browsers are work related; Slack/Teams are neutral; listed social apps and game launchers require context.
- **devshop:** IDEs, terminals, Git and browsers are work related; communication tools are neutral; listed game launchers require context.
- **office:** Office apps, email and browsers are work related; Slack/Teams are neutral; listed social apps and game launchers require context.

Rules apply to the department/job-title cohort. Existing rules are never overwritten, including rules from a previously applied preset; applying the same preset twice is safe. Presets match known executable names, not every product or website. A browser classified as work can still contain personal browsing. Review rules and employee context; exact-domain rules apply only when an address was collected. No new collection or external classification service is introduced.

## Work app context (displayed only)

Admin Performance and employee Work & goals display **Time in work apps**: weekly work-related seconds divided by all categorized seconds (work related + neutral + review context), times 100. Existing ActivityContext daily estimates supply the data. Unclassified time is excluded; no categorized time yields “No categorized data,” not 0%. Cancelled tasks do not enter this calculation. The caption reads “Estimated from role rules. Not a productivity score.”

Work App % has zero scoring weight. Completion, quality, deadlines and collaboration scoring are unchanged. New published snapshots preserve the visible percentage; old snapshots honestly show that it was not recorded. Current estimates may change after role rules change or retention removes records; published snapshots remain unchanged.

## Weekly summary email

Each enabled **super admin** can opt in using **Policy → Email me a weekly summary**. Default is off; reviewers and employees cannot subscribe, and an admin cannot change another user's preference through this endpoint. This preference does not change the capture policy version or employee consent.

The scheduler runs Monday **07:00 UTC** (12:30 IST), reporting the previous Monday–Sunday UTC week. Keep the Laravel scheduler running as described above. Preview safely from the server directory:

```powershell
php artisan tracker:weekly-summary --dry-run
```

The preview sends no email and prints the body even when nobody has opted in. To process opted-in recipients manually, omit --dry-run. Manual reruns can send the same week's summary again; scheduled execution uses withoutOverlapping. Do not run multiple independent schedulers against the same deployment without a shared cache/operational coordination.

Plain-text summaries include retained active hours, up to five employees by active hours, five applications by sample count, weekly screenshot/deletion totals and deletion employee names. Pending flag and leave counts reflect the time of generation. They contain no performance scores, performance rankings, screenshot contents, URLs or deletion/leave reasons. Active hours use the existing overlap-aware attendance calculation and exclude idle/legacy time. Retention can make historical totals incomplete.

Use your organization's configured Laravel mail transport. With MAIL_MAILER=log (the default example), mail is recorded in the private server log and is not delivered externally; this is a successful run. Configure your approved SMTP service and sender through existing MAIL_* settings if delivery is desired, then clear cached configuration. This release introduces no new email vendor, tracking pixel, analytics service or third-party integration. Log-mode summaries contain employee names and should follow your private-log access and retention practices. Transport failures return a nonzero command exit code; successful handoff does not prove mailbox delivery. Inspect application logs and mail-server delivery status. We do not enable subscriptions or send live test emails during installation.

## Upgrade to v1.5.0

Back up database and private storage. Preserve .env, APP_KEY, files and agent settings. Copy the server code, including resources/views/emails, and compiled UI; run php artisan migrate --force, php artisan optimize:clear, and php artisan queue:restart. Existing idle values are preserved; newly created policies default to 120 seconds. Weekly-summary preferences are off. Video and input-timing consent and neutral deletion behavior remain unchanged. Update the EXE to embed the new employee UI. Rebuild/install private agent release files using scripts/prepare-agent-download.ps1 for admin Downloads.
