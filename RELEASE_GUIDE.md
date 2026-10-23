# Publishing v1.5.5 on GitHub

## 1. Upload the source

Extract `activity-platform-v1.5.5-source.zip`. The `activity-platform` folder is the repository root: it contains `agent`, `server`, `web`, `scripts`, and the README. Upload these contents, not the ZIP itself, to an empty GitHub repository.

For Git users, run from that extracted folder, replacing YOUR_ACCOUNT and YOUR_REPOSITORY:

```powershell
git init
git add .
git commit -m "Release Activity Platform v1.5.5"
git branch -M main
git remote add origin https://github.com/YOUR_ACCOUNT/YOUR_REPOSITORY.git
git push -u origin main
```

The project has no selected project-wide license. Add a root LICENSE reflecting your intended permissions before inviting public reuse. Laravel skeleton metadata and dependency licenses do not automatically license this entire application.

## 2. Create the application release

In your GitHub repository, open **Releases → Draft a new release**. Create the tag `v1.5.5` on `main`, title it **Activity Platform v1.5.5**, and use the feature list and limitations in `CHANGELOG.md` for the description.

Attach:

- `activity-agent-v1.5.5-windows-x64.zip` — ready-to-run employee application, shortcut setup and employee instructions.
- `activity-platform-v1.5.5-source.zip` — the reviewed source distribution, including compiled Vue assets.
- `SHA256SUMS.txt` — checksums for those two files.

Publish after reviewing the release. The executable is unsigned; this package is a portable Windows x64 application, not an MSI installer. The source ZIP deliberately excludes EXEs. Do not commit employees' local agent folders or runtime data to Git.

## 3. Install and connect

Follow `README.md` to install the Laravel/MySQL server or upgrade an existing installation. The employee application needs that server; the Windows ZIP does not contain Laravel or MySQL. Use a reachable HTTPS server address with a trusted certificate. A Herd `.test` address works locally and is not automatically reachable from other computers.

Employees extract their Windows ZIP into a permanent writable folder, double-click `Setup Desktop Shortcut.cmd`, then use the **Activity Tracker** desktop shortcut. Server address, device name and login are entered in the application window. Monitoring starts after employee sign-in and follows the server policy.

On upgrade, keep the server's `.env`, `APP_KEY`, database and private storage, and the employee's `agent-config.json` and `data` folder. New personal-profile fields are encrypted using the existing application key; regenerating that key can make them unreadable.

## 4. Rebuild the release

Use Windows x64, PowerShell 7, PHP 8.4, Composer 2, Node 22.12+ and Go 1.22+. From the repository root:

```powershell
composer install --working-dir=server
npm ci --prefix web
node scripts/build-web.mjs
./scripts/build-agent.ps1
./scripts/prepare-agent-download.ps1
./scripts/package.ps1 -SourceOnly
```

The Go build script runs agent tests and static checks. Run Laravel tests from `server` (`php artisan test`) using the included SQLite test configuration, and frontend tests using `npm test --prefix web`. Never point RefreshDatabase tests at a real employee database. Package a fresh employee ZIP containing only the EXE, shortcut scripts, example configuration and employee instructions; never include `data` or `agent-config.json`.

For checksums, use `Get-FileHash -Algorithm SHA256 <archive>` and compare with `SHA256SUMS.txt`. Rebuilt binaries may have different checksums with a different toolchain; the provided checksums identify the exact supplied release files.

## Validation and limits

For v1.5.5, Go tests and static checks, frontend utility tests, the production Vue build and browser workflow checks passed. A targeted browser check confirms that saving during a pending background refresh fetches fresh data and rejects the older response. A new Go test verifies upload cancellation during sign-out. The server code is unchanged from v1.5.1, whose SQLite suite passed 64 tests / 523 assertions.

This patch has no database migrations. The packaged changes have not been deployed to the existing Herd installation automatically.

Native screen capture across Windows display setups, optional video recording with FFmpeg/FFprobe, real FTPS destinations, SMTP mailbox delivery and Docker deployment still need environment-specific acceptance testing. The executable is unsigned.

This is a single-organization platform. HR review is not identity verification. Payroll, leave balances, identity-document uploads, scheduled shifts, all-running-process inventories and signed installers are not included. See README.md and OPTIONAL-MODULES.md for setup and limitations.