# Trust & deployment facts

Activity Platform v1.5.1 · 22 October 2026

This document describes the supplied implementation. It is not a legal assessment, compliance certification, security audit, or promise that any particular deployment meets a regulation. Deployment owners determine appropriate use, notices, access and retention for their organization.

## What is collected

- Desktop screenshots while signed in, active and allowed by policy; foreground application names and window titles.
- Optional supported-browser addresses: site/path, with embedded credentials, query and fragment removed. Paths and titles can still be sensitive.
- Active/idle estimates, device and login/session details, server timestamps, queue status and audit records including request IP addresses.
- Employee-entered personnel/contact/address/emergency/education details, employment fields, leave requests, work evidence, context and manager reviews.
- If enabled and acknowledged for the current policy: short silent screen clips, and qualifying aggregate input-timing evidence. No AI/ML processing is included.

## What we do not collect as separate records

- No keystroke-content log or clipboard scraping.
- No separate mouse-coordinate or click-history log. Last-input timestamps support idle detection; optional timing review uses aggregate intervals.
- No file-system scan or automatic upload of personal documents.
- No webcam or microphone recording.
- No extraction of passwords from browsers or password managers.
- No dedicated government-ID or payroll fields; free-text fields should not be used for these.
- No background-process inventory; activity samples identify the foreground application and window.

**These are collection-method boundaries, not a promise that captured content is non-sensitive.** Screenshots, optional silent video, window titles and optional URLs can contain personal information, visible credentials or document text. Automated redaction and sensitive-app exclusions are not implemented. Sign out or quit the agent to stop capture before displaying sensitive content. Previously collected data remains subject to retention and deletion rules.

The application processes its own login credentials to authenticate users. Server account passwords are hashed. The agent does not save the password, but does save a server-issued token protected by the current Windows user's DPAPI when available. Configured FTPS and mail credentials remain server-side.

## Default retention and storage limits

| Record | Default / behavior |
| --- | --- |
| Screenshots, app activity, timing-review evidence | 30 days, configurable; scheduled pruning required |
| Silent video clips | 7 days; 1024 MB quota per employee; both configurable |
| Audit and screenshot-deletion events | 365 days, configurable |
| Local offline queue | 7 days and 512 MB per device, configurable |
| Attendance, profiles, leave and published outcome reviews | No automatic deletion schedule is supplied; administrator-managed retention is required |
| Backups, downloads and server logs | Not erased by application capture deletion; controlled by deployment operations |

Retention runs with tracker:prune daily at 02:00 UTC. Failures must be investigated; a configured retention value does not prove storage removal. Pending employee screenshot deletion stays hidden while file cleanup retries.

## Role access

| Capability | Employee | Reviewer | Super admin |
| --- | --- | --- | --- |
| Screenshot/activity records | Own records | Organization records | Organization records |
| Silent video playback | Own clips | Organization clips | Organization clips |
| Screenshot deletion | Own screenshots | No employee-deletion action | Review deletion events; no impersonation action |
| Timing-review evidence | Own evidence and explanations | No | Review with a reason |
| HR details and leave | Own profile/requests; employment fields read-only | No | Personnel and leave administration |
| Outcome reviews | Own work and published results | No | Assign, review and publish; same-role rankings |
| Policy, users, device controls, audit/deletion logs | No | No | Yes |
| Weekly email | No | No | Personal opt-in, off by default |

This is a single-organization application. Hosting/database administrators can access the underlying infrastructure outside application roles. Employees see their retained capture records, not all organization-wide audit logs or other employees' information.

## Notice and optional-module acknowledgment

The employee reads and accepts the current capture notice at login. Core screenshot/app capture starts after sign-in when policy permits. Optional video and timing review are off by default and require separate acknowledgment of the current policy version. Policy updates require a fresh optional-module acknowledgment. Status is available in the agent UI and Windows tray; closing the browser tab does not stop the agent. Sign out or quit to stop capture. These product controls do not establish any particular legal basis by themselves.

## Neutral screenshot deletion

Employees can delete their own uploaded screenshots. The original and thumbnail are removed from active storage; the retained record uses one shared organization placeholder with exact deletion metadata. Audit history remains. Storage failures keep the original hidden while cleanup retries. No flags or score penalties result from deletion. Video deletion is not part of the employee deletion feature. Already downloaded copies, in-flight responses and backups cannot be recalled.

## Data location and external services

The organization chooses where Laravel, the database and private storage run. Local private storage is the default; an administrator can configure FTPS and the existing mail transport. These destinations may be external providers chosen by the organization. There is no built-in vendor telemetry, AI analysis service or mandatory vendor cloud. Weekly summaries contain employee names and activity totals; log mode keeps them in private server logs rather than delivering mail. Review all chosen hosting, backup, FTPS and mail services before making data-residency claims.

## Encryption and boundaries

Personal-details JSON is encrypted using Laravel's existing APP_KEY. Employment fields, attendance, screenshots/video and other records are not all application-encrypted at rest. Use appropriate disk/database encryption and backup controls for your deployment. Preserve APP_KEY; changing it can make encrypted profile data unreadable.

The Windows session token uses current-user DPAPI, presently through Windows PowerShell. Local queued screenshots/video are not DPAPI-encrypted. Protect the Windows account and folders. If DPAPI is unavailable, the agent can use an in-memory session and requires sign-in after restart. HTTPS is required for non-loopback agent servers; certificate-verified FTPS is supported. Secure transport does not protect a compromised endpoint.

## Outcomes and operational review

Activity and Work App % are context only. Scores use manager-reviewed outcomes, never screenshot deletion, idle time or timing flags. Timing patterns can arise from legitimate work and require human review. Native Windows capture, real FTPS operation and actual SMTP delivery need acceptance testing in your environment. See deploy/CHECKLIST.md and OPTIONAL-MODULES.md.
