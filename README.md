# Activity Platform

Two applications: a Vue 3 admin dashboard backed by Laravel 13 and MySQL, and a Go Windows desktop agent with an embedded Vue status/login screen.

## Source package and project layout

The GitHub source ZIP includes all application source, dependency lockfiles, tests, deployment files and compiled Vue assets. It excludes installed dependencies, executables, credentials, runtime databases, employee screenshots and logs. Run the commands below to install dependencies and build the Windows executable. Use Node 22.12+ and PowerShell 7 for the supplied build/packaging scripts.

| Folder | Contents |
| --- | --- |
| `server/` | Laravel API, administrator authentication, migrations, jobs and tests |
| `web/` | Vue administrator dashboard and agent interface |
| `agent/` | Go Windows collector, queue, embedded frontend and tests |
| `deploy/` | Docker, PHP and Nginx configuration |
| `scripts/` | Frontend build, Windows agent build and release packaging |

Choose **Herd** below for Windows development, **Quick local demonstration** for SQLite, or **Docker / MySQL** for containers. Only `web/` needs npm installation; the Laravel skeleton's `server/package.json` is not the dashboard build.

## Windows installation with Laravel Herd

1. Extract the ZIP, preserving the `activity-platform` directory. Install PHP 8.4 in Herd, Node 22.12+, and MySQL (Herd Pro Services or a separate MySQL server). To build the agent, install Go 1.22+ too.
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

Copy `agent/agent-config.example.json` to `agent/agent-config.json`, set `server_url` to `https://activity.test`, and start the resulting `agent/activity-agent.exe`. Create an employee in the dashboard and log in through the agent. Monitoring begins after employee login and notice acceptance when policy permits it. The local `.test` address is only for this computer; other machines require a reachable HTTPS deployment.

PHP needs GD for thumbnails and PDO MySQL for MySQL. Set `upload_max_filesize=20M`, `post_max_size=24M`, `memory_limit=512M`, and ensure Nginx permits 24 MB requests. Restart affected Herd services after PHP configuration changes. If a certificate error occurs, repair Herd's local certificate trust rather than disabling TLS verification.

References: [Herd site linking](https://herd.laravel.com/docs/windows/getting-started/sites), [PHP isolation](https://herd.laravel.com/docs/windows/technology/php-versions), [HTTPS](https://herd.laravel.com/docs/windows/sites/securing-sites).

## Implemented

- Real server authentication; employee enrollment; administrator and reviewer roles; disabled accounts and revoked device tokens.
- Live local clock, visible monitoring notice, Windows tray icon and console status.
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

## Quick local demonstration

Requirements: PHP **8.4+**, Composer 2, Node **22.12+**, and MySQL 8.4 for normal deployment. PHP extensions: PDO MySQL, PDO SQLite (tests/demo), mbstring, fileinfo, OpenSSL, cURL, GD, ZIP and Intl. Go 1.22+ is needed only to rebuild the agent.

From this directory:

```powershell
cd web
npm ci
cd ..
node scripts/build-web.mjs
cd server
composer install
Copy-Item .env.example .env
```

Set `DB_CONNECTION=sqlite` in `server/.env`, remove or comment `DB_DATABASE=activity_platform`, and create an empty `server/database/database.sqlite`. Keep `APP_ENV=local`. Then:

```powershell
php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://127.0.0.1:8000. Demo accounts are `admin@example.test`, `reviewer@example.test` and `employee@example.test`, all with password `LocalDemo!2026`. They are created **only** by explicitly running DemoSeeder, which refuses production environments. Gallery images are synthetic. Do not seed demo accounts on a real deployment.

Run `php artisan queue:work --tries=3 --timeout=120` and `php artisan schedule:work` in separate terminals during local use. The scheduler is required for retention cleanup.

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
3. Start `activity-agent.exe`. It opens http://127.0.0.1:8765/agent. Read the monitoring notice and sign in using an employee account.
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

`ftp://` uses explicit TLS (`--ssl-reqd`); `ftps://` uses implicit TLS. Certificates must be trusted. The service account needs create, read, write, list and delete permissions. Use **Policy → Verify storage connection** before enabling monitoring. Failed FTPS writes are not acknowledged, so the agent retains the upload for retry. The agent always sends to Laravel's API, which handles the FTPS destination. Arbitrary third-party upload APIs and plaintext FTP are not supported.

Existing FTPS records refer to the configured destination. Do not change its host/base folder while retained files remain there without migrating those files. Server credentials must never be included in a source archive or employee config.

## Validation and known limits

Verified in this workspace: Laravel feature suite (17 tests, 73 assertions), Go queue/client tests (12 tests), Go vet/build, Vue production build and frontend utility tests (2). Browser checks use the local SQLite demonstration. Run tests yourself with `php artisan test`, `go test ./...`, `go vet ./...` and `npm test` in the appropriate directories.

MySQL/Docker deployment and a real FTPS endpoint have **not** been exercised in this environment. Native screen capture, foreground detection, tray behavior and DPAPI need an interactive Windows smoke test; sandboxed automation cannot validate those desktop integrations fully. Browser URL detection is best effort and depends on browser accessibility support; it is off by default. Secure/locked desktops and protected windows may not be capturable. This is foreground sampling, not a list of all running apps, idle detection, keystroke logging or a productivity score.

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
