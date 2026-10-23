# Start the employee tracker

1. Keep the complete agent folder in a permanent, writable location on the employee's computer.
2. Double-click **Setup Desktop Shortcut.cmd** once to create the desktop shortcut. No configuration file is required.
3. Double-click **Activity Tracker** on the desktop. A visible console and employee browser window open. Startup errors stay visible in the console.
4. On the employee page, enter the server address (for example `https://ourteam.test`) and device name, then select **Save & connect**. The agent checks the server and saves `agent-config.json` beside the executable. Sign in with an employee account and read the capture notice. Keep the console running; closing the browser alone does not stop collection. Sign out or close the agent to stop.
5. The employee window shows the latest screenshot whose upload started, its capture time, and whether the server confirmed receipt. Offline captures stay queued; the preview appears when an upload is attempted. It retains only one image in memory and clears on sign-out or exit; it is not a screenshot history.

Clicking the shortcut again opens the existing agent's page when that executable is already running. If you move the agent folder, run the shortcut setup again. The setup does not install an automatic-start service.

For employees on other computers, use a server address reachable from those computers with a trusted HTTPS certificate. A local Herd `.test` address is not automatically available to other computers.

## Application and URL collection

The current agent samples the foreground application and its window title (by default every five seconds). It does not enumerate all running/background processes and cannot guarantee detection of applications opened between samples. Collection runs while signed in and enabled by policy, not according to a work-hours schedule.

Admins can enable browser addresses in the capture policy. The agent attempts to read the active address bar in Chrome, Edge, Firefox, and Brave using Windows accessibility APIs. Availability depends on browser/UI language and accessibility support. Query strings, fragments and embedded credentials are removed. It does not collect all tabs or browsing history.

## Saved settings and login

Connection settings are stored in agent-config.json. A successful login saves the server-issued session, including the employee identity, in data/session.bin using Windows DPAPI for the current Windows user. The password is not saved. Valid sessions restore after restart; expired or revoked sessions require signing in again. Sign out before changing connection settings. Existing configuration is retained during upgrades: replace the application files while keeping agent-config.json and the data folder.

## Employee workspace

You may see a 'Time in work apps' percentage. It is context for managers, not a productivity score. New installations default to a 120-second idle threshold; your company can configure 30–600 seconds. Existing settings are preserved.

The new workspace has Overview, My time, Login history, Leave requests, My profile and Device settings tabs. Your administrator must deploy this release's Laravel code and run php artisan migrate before the HR screens work. Time and session history start after the upgrade. Active hours exclude idle/locked states, pauses and gaps over 60 seconds; they are estimates, not payroll measurements. After your configured idle threshold without keyboard/mouse input, screenshot capture and uploads pause and resume automatically on activity. Previously captured screenshots remain queued. There is no keystroke-content log; screenshots and optional video can still contain text visible on screen. The My time page separates active, idle and legacy totals. Change your reporting timezone in My profile. Leave requests require administrator approval and do not automatically stop capture.

## Personnel profile and your uploaded records

My profile now contains personal, address, emergency-contact and education details. HR manages the read-only employment fields and review status. Use My data to browse your retained server screenshots and application activity, including collected URLs. This requires the latest Laravel update and migrations. HR review is not identity verification, and no identity-document uploads are included.


### Work & goals (v1.2.0)
Your administrator assigns work with clear success criteria. Open Work & goals, select a Monday reporting date, submit completed results and add context for meetings, reading or offline work. Show open work displays the earliest 50 unfinished items across weeks. The scoring rules are visible in the page. Only manager-published results receive a score; activity and idle time do not change it. Your published review includes the evidence and manager explanation. In My data, open a screenshot and expand Image information to see its metadata. Both the server and agent must be updated for these features.

## Video, activity reviews and deletion (v1.4.0)

Optional video and timing review start only after your company enables them and you acknowledge the current module notice. Videos shows your retained silent screen clips; Activity reviews shows timing evidence and lets you add explanations. Neither affects scores automatically. The status and tray tooltip show recording. Sign out or quit the agent to stop capture. Your administrator must install FFmpeg (beside the EXE or on PATH) for video. No video codec is bundled.

In My data, choose Delete screenshot and confirm, optionally adding a reason. A shared placeholder and deletion record remain. A pending-cleanup message means the image is hidden while storage removal retries. No deletion flags or penalties apply. Previously downloaded copies and backups cannot be recalled.
