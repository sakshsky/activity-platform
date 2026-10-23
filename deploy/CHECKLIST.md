# IT deployment checklist

## Host and application

- [ ] PHP 8.4.1+ compatible with the lockfile; Composer 2; MySQL 8.4 for the supplied Compose setup. SQLite is supported for local demos/tests.
- [ ] PHP extensions required by composer check-platform-reqs, including PDO MySQL, mbstring, fileinfo, OpenSSL, cURL, GD, ZIP, Intl and FTP. PDO SQLite is needed for demo/tests. CLI and web PHP must agree.
- [ ] Nginx/reverse proxy uses server/public as document root. PHP-FPM is private. APP_ENV=production, APP_DEBUG=false, a stable APP_KEY, HTTPS APP_URL and secure session cookies.
- [ ] PHP upload_max_filesize=20M, post_max_size=24M, memory_limit=512M; proxy request limit at least 24 MB.

## Network exposure

| Connection | Typical port | Exposure |
| --- | --- | --- |
| Employee/browser to web | TCP 443 | Reachable HTTPS origin and trusted certificate |
| HTTP redirect / certificate validation | TCP 80 if required | Only when needed by your reverse proxy / certificate setup |
| Nginx to PHP-FPM | TCP 9000 in supplied containers | Private network only; never expose publicly |
| App to MySQL | TCP 3306 by default | Private network only; restrict to app/operations hosts |
| Optional FTPS control | TCP 21 explicit TLS or 990 implicit TLS | Outbound from server to configured storage; provider-defined passive data ports also required |
| Optional mail | Transport-dependent | Outbound to your approved mail server only; not required in log mode |
| Agent local UI | Loopback TCP 8765 | Local Windows user session only; do not forward publicly |
| Docker development web | Loopback TCP 8080 | Supplied Compose binds 127.0.0.1; add a suitable HTTPS proxy for remote agents |

Do not open all these ports on the public firewall. The agent uploads to Laravel over HTTPS; it does not connect directly to MySQL or FTPS. The supplied app is single-organization: use separate instances, databases, keys and storage for separate customers. Shared multi-tenant hosting inside one application is not supported.

## Storage and backups

- [ ] server/storage/app/private holds private capture data; server/storage/app/agent-release holds authenticated EXE/ZIP downloads. Never publish a storage symlink for these folders.
- [ ] PHP/worker identities can write storage and bootstrap/cache. Restrict access to .env and logs; protect backup and export copies.
- [ ] Back up the database, private storage, configuration and APP_KEY; test a restore. Preserve them on upgrades. Backups require their own retention policy.
- [ ] Optional FTPS: validate certificates and write/read/delete permissions using Policy → Check storage connection. Keep host/base-folder mappings stable for retained files.
- [ ] Install FFprobe on the server only if using optional video validation; supply TRACKER_FFPROBE_BINARY. Do not enable video until target-PC tests pass.

## Background jobs

Run one managed scheduler and queue worker per intended deployment arrangement; configure restart and log rotation through your service manager. The local helper processes are not a substitute for services that survive reboot.

```text
php artisan queue:work --sleep=3 --tries=3 --timeout=120
php artisan schedule:work
```

Alternatively invoke php artisan schedule:run every minute via your service scheduler. Inspect php artisan schedule:list. Defaults use UTC: tracker:prune daily at 02:00; sanctum:prune-expired daily at 00:00; tracker:weekly-summary Monday at 07:00. Run only the chosen scheduler mechanism. Weekly email is opt-in and log-only unless a delivery transport is configured. Preview with tracker:weekly-summary --dry-run.

## Windows agents

- [ ] Windows 10/11 x64 with an interactive signed-in user session, trusted HTTPS connectivity and a writable protected app folder.
- [ ] Windows PowerShell and .NET functionality must be allowed for the current screenshot, DPAPI and shortcut helpers. Do not claim a PowerShell-free agent. PowerShell 7 is required for repository build/package scripts.
- [ ] DPAPI protects the saved session for the current Windows user; protect queued image/video files using OS and disk controls.
- [ ] Optional FFmpeg with gdigrab/libx264 beside the EXE or on PATH for silent clips; separate installation, not bundled.
- [ ] Pilot startup, login, visible status, lock/idle transitions, screenshot access/deletion, and optional video on target hardware. EXEs are unsigned; follow your organization's distribution process.

## Release acceptance

- [ ] Back up before deployment; preserve APP_KEY and .env. Run migrations, clear caches, restart workers, update embedded agent UI, and install matching release files for Downloads.
- [ ] Use isolated databases for automated tests. Never run RefreshDatabase tests or DemoSeeder on a live employee database.
- [ ] Review role access, notices, employee visibility, retention, private logs and external mail/storage choices with your deployment owner.
- [ ] No claim of legal compliance or blanket at-rest encryption is implied. Read ../COMPLIANCE.md and ../OPTIONAL-MODULES.md.
