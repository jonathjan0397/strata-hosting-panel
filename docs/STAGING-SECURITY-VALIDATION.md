# Staging Security Validation

Last updated: 2026-04-10
Target release line: `1.0.0`

This runbook defines the minimum staging validation required before Strata can be considered for a production release candidate.

Companion evidence template:
- [STAGING-SECURITY-EVIDENCE-TEMPLATE.md](/C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/docs/STAGING-SECURITY-EVIDENCE-TEMPLATE.md)
- [STAGING-SECURITY-CHECK-COMMANDS.md](/C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/docs/STAGING-SECURITY-CHECK-COMMANDS.md)

It is intended to be executed on a fresh staging deployment that mirrors production closely:

- one primary panel node
- at least one child node
- HTTPS enabled
- mail, DNS, FTP, WebDAV, phpMyAdmin, and webmail enabled

## Goal

Confirm that:

1. the current release is functionally stable
2. the security hardening changes behave as intended
3. the remaining known risks are either resolved or explicitly accepted

## Environment Prerequisites

- fresh install from a tagged release, not a live-patched tree
- release deployed through `/usr/sbin/strata-upgrade`
- separate test identities for:
  - `admin`
  - `reseller`
  - `user`
- at least two user accounts under different ownership boundaries
- at least two hosted domains on different accounts
- a valid test mailbox
- a valid FTP account
- a valid WebDAV account
- a valid database and phpMyAdmin login

## Validation Order

1. install and upgrade validation
2. authentication and session checks
3. authorization and tenant isolation checks
4. service exposure and transport checks
5. mail, DNS, and backup safety checks
6. browser and header checks
7. external scan pass
8. evidence capture and signoff

## 1. Install And Upgrade Validation

- perform a fresh install from the release being tested
- verify the installer shows the exact release version
- verify install completes with:
  - panel accessible
  - primary node online
  - child node online
- run one release upgrade from the previous tag to the target tag
- confirm rollback backup creation succeeds
- confirm rollback backup permissions:
  - `/opt/strata-panel-backups` should be `0700`
  - latest backup directory should be `0700`
  - `metadata.json` should be `0600`
- verify upgrade logs appear in the panel log viewer
- verify rollback can be started from a backup in staging

Evidence:

- `php artisan about`
- update page screenshot
- `stat`/`ls -ld` output for rollback directories
- upgrade and rollback logs

## 2. Authentication And Session Checks

- verify admin login works with 2FA
- verify reseller login works with 2FA if enabled
- verify user login works with 2FA if enabled
- trigger repeated bad password attempts and confirm throttling
- trigger repeated bad 2FA attempts and confirm throttling
- verify logout invalidates the session
- verify session cookies are:
  - `HttpOnly`
  - `Secure` on HTTPS
  - `SameSite=Lax` unless intentionally overridden

Evidence:

- browser screenshots
- response headers / cookie inspection
- failed-attempt behavior notes

## 3. Authorization And Tenant Isolation Checks

### Admin

- confirm all expected admin sections render
- confirm admin can:
  - manage nodes
  - manage accounts
  - manage DNS
  - manage updates

### Reseller

- confirm reseller only sees reseller-owned clients
- confirm reseller search does not reveal unrelated accounts
- confirm reseller impersonation only works on owned accounts
- confirm reseller cannot access admin-only routes directly

### User

- confirm user can access only owned:
  - domains
  - DNS zones
  - mailboxes
  - FTP accounts
  - WebDAV accounts
  - databases
  - backups
  - app installs
- manually attempt direct-object access by changing IDs in URLs and requests
- confirm cross-account access is denied consistently with `403` or `404`

Evidence:

- route test notes
- screenshots or recorded request results for denied access attempts

## 4. Service Exposure And Transport Checks

- verify browser shell is hidden by default
- verify browser shell cannot be opened unless explicitly enabled
- verify node details page does not expose raw node HMAC secrets
- verify agent communication still works with proxy trust enabled if the panel is behind a proxy
- verify baseline security headers on panel responses:
  - `X-Frame-Options`
  - `X-Content-Type-Options`
  - `Referrer-Policy`
  - `Permissions-Policy`
  - `Cross-Origin-Opener-Policy`
  - `Strict-Transport-Security` on HTTPS

Evidence:

- browser devtools screenshots
- curl/header capture

## 5. Mail, DNS, Backup, And File Safety Checks

### Mail

- create mailbox
- log in through webmail
- send and receive mail
- verify DKIM, SPF, and DMARC status
- verify Outlook/SMTP submission using the shared server mail hostname

### DNS

- create hosted domain
- verify authoritative zone exists
- verify backup sync runs without drift
- verify DNS repair/troubleshooting actions still work

### Backups

- create manual backup
- download backup
- restore backup
- restore a specific path
- verify restored files stay within account boundaries

### File Operations

- file upload
- file download
- rename
- delete
- archive compress
- archive extract
- verify path traversal attempts are rejected

Evidence:

- sample logs
- screenshots
- restored file tree verification

## 6. Browser And UX Gate

- verify admin sidebar renders:
  - `Resellers`
  - `Security`
  - `System`
  - `Infrastructure`
  - `Hosting`
- verify reseller sidebar renders expected reseller sections
- verify user sidebar renders expected user sections
- verify no browser console errors after login for all three roles
- verify update page:
  - shows current version
  - shows latest published release
  - shows advanced sources
  - shows progress/log viewer

Evidence:

- screenshots per role
- console capture per role

## 7. External Scan Pass

Run the following against staging:

- web TLS scan
- mail TLS scan
- DNS exposure review
- basic header/security scan
- authenticated browser smoke test
- targeted manual checks for:
  - IDOR
  - CSRF
  - XSS
  - SSRF
  - command injection
  - archive/path traversal

Recommended public tools:

- `nmap`
- `sslyze` or equivalent TLS scanner
- Mozilla Observatory or equivalent header scan
- mail/DNS validation tools

## 8. Signoff Criteria

Do not move toward a production release until:

- all validation sections above are executed
- no unresolved critical or high findings remain
- browser shell policy is explicitly decided for production
- license secret transport issue is either remediated or consciously accepted with documented rationale
- rollback and restore have been proven in staging

## Evidence Checklist

- install logs
- upgrade logs
- rollback logs
- screenshots for admin/reseller/user
- header capture
- denied-access test results
- mail delivery test results
- DNS sync/repair results
- backup restore results
- external scan outputs

## Follow-Up Outputs

After running this checklist, update:

- [SECURITY-PRODUCTION-READINESS.md](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/docs/SECURITY-PRODUCTION-READINESS.md)
- [DEVELOPMENT-HANDOFF.md](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/docs/DEVELOPMENT-HANDOFF.md)

Record:

- date of validation
- release tested
- findings
- pass/fail decision
