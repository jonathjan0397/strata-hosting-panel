# Code Review Changes

This file tracks code-review-driven changes made on the `code-review-fixes` branch so they stay separate from `main`.

## Branch

- Branch: `code-review-fixes`
- Base branch at review start: `main`

## Commits

### `aca6e04` - `fix: address code review regressions`

This commit addressed the first targeted review findings:

- Returned the actual provisioned PHP version from the agent in `agent/internal/account/provision.go`.
- Persisted the actual PHP version in the panel after account provisioning in `panel/app/Services/AccountProvisioner.php`.
- Refreshed the account before domain creation and used the real PHP version for the domain/vhost path in `panel/app/Http/Controllers/Admin/AdminWebsiteController.php`.
- Added rollback/cleanup when admin website vhost provisioning fails so the panel does not leave orphaned account/domain state in `panel/app/Http/Controllers/Admin/AdminWebsiteController.php`.
- Changed the DNS server-zones table to key rows by `zone_name` and hide Manage/Delete actions for live-only zones without a DB id in `panel/resources/js/Pages/Admin/Dns/ServerZones.vue`.
- Updated PHP-FPM reload logic to prefer graceful reload and fall back to restart if the service is inactive in `agent/internal/account/provision.go`.

Validation performed for this commit:

- `gofmt` on `agent/internal/account/provision.go`
- PHP syntax lint on:
  - `panel/app/Services/AccountProvisioner.php`
  - `panel/app/Http/Controllers/Admin/AdminWebsiteController.php`

### `04cc9bf` - `fix: address audit findings on panel and agent flows`

This commit addressed the broader codebase audit findings:

- Changed the `User` model `account()` relation from `hasMany` to `hasOne` in `panel/app/Models/User.php` to match the rest of the codebase's single-account assumption.
- Fixed deliverability domain loading to use the correct `domain` column in `panel/app/Http/Controllers/DeliverabilityController.php`.
- Corrected broken app installer route names from `user.apps.installed` to `my.apps.installed` in:
  - `panel/app/Http/Controllers/User/AppInstallerController.php`
  - `panel/resources/js/Pages/User/Apps/Catalog.vue`
- Added a generic `url()` helper to the `Node` model and changed agent API URLs to use the node hostname when available in `panel/app/Models/Node.php`.
- Removed unconditional TLS verification bypass from:
  - `panel/app/Services/AgentClient.php`
  - `panel/app/Http/Controllers/User/FileManagerController.php`
- Fixed the billing API account controller to call `AccountProvisioner` through the container/service API instead of invalid constructor calls in `panel/app/Http/Controllers/Api/V1/AccountController.php`.
- Expanded API PHP version validation/defaults to include `8.4` in `panel/app/Http/Controllers/Api/V1/AccountController.php`.
- Prevented local database grant records from being deleted when agent-side revoke fails in:
  - `panel/app/Http/Controllers/User/DatabaseController.php`
  - `panel/app/Http/Controllers/Admin/DatabaseController.php`
- Prevented local mailbox and forwarder records from being deleted when agent-side delete fails in `panel/app/Services/MailProvisioner.php`.
- Hardened admin shell token signing by including the panel origin and verifying the WebSocket `Origin` header in:
  - `panel/app/Http/Controllers/Admin/ShellController.php`
  - `agent/internal/api/shell_handler.go`

Validation performed for this commit:

- `gofmt` on `agent/internal/api/shell_handler.go`
- PHP syntax lint on:
  - `panel/app/Models/User.php`
  - `panel/app/Models/Node.php`
  - `panel/app/Http/Controllers/Api/V1/AccountController.php`
  - `panel/app/Services/AgentClient.php`
  - `panel/app/Http/Controllers/Admin/DatabaseController.php`
  - `panel/app/Http/Controllers/Admin/ShellController.php`
  - `panel/app/Http/Controllers/DeliverabilityController.php`
  - `panel/app/Http/Controllers/User/AppInstallerController.php`
  - `panel/app/Http/Controllers/User/DatabaseController.php`
  - `panel/app/Http/Controllers/User/FileManagerController.php`
  - `panel/app/Services/MailProvisioner.php`

## Notes

- All changes listed here were made on `code-review-fixes`.
- No changes in this file were applied directly to `main`.
- Additional audit fixes should be appended here as new sections if more review-driven work is added to this branch.

## Current Audit Pass

This audit pass addressed the next batch of review findings on `code-review-fixes`:

- Fixed the agent HMAC middleware to restore authenticated request bodies with `bytes.NewReader` instead of a truncating custom reader in `agent/internal/api/hmac.go`.
- Hardened app installer path validation in `agent/internal/api/app_handlers.go` so install, update, and uninstall requests are restricted to `/var/www/{site_owner}/...`, and added `site_owner` to uninstall requests.
- Prevented panel SSH key deletion from removing the local record when the agent-side delete fails in `panel/app/Http/Controllers/User/SshKeyController.php`.
- Prevented autoresponder deletion from removing the local record when the agent-side delete fails in `panel/app/Http/Controllers/User/AutoresponderController.php`.
- Changed user app uninstall to keep the installation record unless the node-side uninstall succeeds in `panel/app/Http/Controllers/User/AppInstallerController.php`.
- Changed admin domain deletion to keep the domain record unless remote deprovision succeeds in `panel/app/Http/Controllers/Admin/DomainController.php`.
- Changed admin and reseller account deletion to keep the account and user records unless remote deprovision succeeds in:
  - `panel/app/Http/Controllers/Admin/AccountController.php`
  - `panel/app/Http/Controllers/Reseller/AccountController.php`
- Changed admin and user backup deletion to keep the backup job record unless node-side deletion succeeds in:
  - `panel/app/Http/Controllers/Admin/BackupController.php`
  - `panel/app/Http/Controllers/User/BackupController.php`

Validation performed for this audit pass:

- `gofmt` on:
  - `agent/internal/api/hmac.go`
  - `agent/internal/api/app_handlers.go`
- PHP syntax lint on:
  - `panel/app/Http/Controllers/User/SshKeyController.php`
  - `panel/app/Http/Controllers/User/AutoresponderController.php`
  - `panel/app/Http/Controllers/User/AppInstallerController.php`
  - `panel/app/Http/Controllers/Admin/DomainController.php`
  - `panel/app/Http/Controllers/Admin/AccountController.php`
  - `panel/app/Http/Controllers/Reseller/AccountController.php`
  - `panel/app/Http/Controllers/Admin/BackupController.php`
  - `panel/app/Http/Controllers/User/BackupController.php`

## Current Audit Pass 2

This audit pass addressed file-manager trust-boundary issues and the next set of remote-state mismatches:

- Hardened jail path resolution in `agent/internal/files/manager.go` by resolving symlinks before allowing file operations, so symlinked paths cannot escape `/var/www/{user}`.
- Fixed multipart file-upload signing in `panel/app/Http/Controllers/User/FileManagerController.php` by constructing the actual multipart payload, signing the real request body, and sending it with the matching content type.
- Changed the billing API account terminate endpoint to keep local panel state when remote deprovision fails in `panel/app/Http/Controllers/Api/V1/AccountController.php`.
- Changed SSL issuance and custom SSL storage flows to only persist `ssl_enabled` state after the vhost reprovision step succeeds in `panel/app/Services/DomainProvisioner.php`.

Validation performed for this audit pass:

- `gofmt` on:
  - `agent/internal/files/manager.go`
- PHP syntax lint on:
  - `panel/app/Http/Controllers/User/FileManagerController.php`
  - `panel/app/Http/Controllers/Api/V1/AccountController.php`
  - `panel/app/Services/DomainProvisioner.php`

## Current Audit Pass 3

This audit pass addressed domain rollback behavior and DNS priority persistence:

- Changed user domain creation to remove the local `domains` row when vhost provisioning fails in `panel/app/Http/Controllers/User/DomainController.php`.
- Added rollback behavior for user domain directive and redirect edits so panel state is reverted if the live vhost reprovision step fails in `panel/app/Http/Controllers/User/DomainController.php`.
- Changed the admin "My Website" deprovision flow to keep panel state when remote domain or account cleanup fails in `panel/app/Http/Controllers/Admin/AdminWebsiteController.php`.
- Added DNS priority persistence to the provisioning layer in `panel/app/Services/DnsProvisioner.php`.
- Updated admin and user DNS record create/import flows to pass priority through and to parse MX priorities correctly from imported zone text in:
  - `panel/app/Http/Controllers/Admin/DnsController.php`
  - `panel/app/Http/Controllers/User/DnsController.php`

Validation performed for this audit pass:

- PHP syntax lint on:
  - `panel/app/Services/DnsProvisioner.php`
  - `panel/app/Http/Controllers/Admin/DnsController.php`
  - `panel/app/Http/Controllers/User/DnsController.php`
  - `panel/app/Http/Controllers/User/DomainController.php`
  - `panel/app/Http/Controllers/Admin/AdminWebsiteController.php`

## Current Audit Pass 4

This audit pass addressed security mutation-on-read behavior, backup transport hardening, and the remaining app-installer route mismatches:

- Removed UFW auto-install and auto-enable behavior from the agent and changed firewall reads and writes to return a service-unavailable error when `ufw` is not installed in `agent/internal/api/firewall_handlers.go`.
- Removed fail2ban auto-install and auto-enable behavior from the agent and changed status and unban requests to return a service-unavailable error when `fail2ban-client` is not installed in `agent/internal/api/fail2ban_handlers.go`.
- Added `currently_failed` parsing to the fail2ban jail payload in `agent/internal/api/fail2ban_handlers.go` so the admin firewall UI receives the field it already expects.
- Removed `StrictHostKeyChecking=no` from SFTP backup pushes in `agent/internal/api/remote_backup_handlers.go` so remote backup transfers require normal SSH host-key verification.
- Corrected the remaining app installer route names from `user.apps.*` to `my.apps.*` in:
  - `panel/resources/js/Layouts/AppLayout.vue`
  - `panel/resources/js/Pages/User/Apps/Catalog.vue`
  - `panel/resources/js/Pages/User/Apps/MyApps.vue`

Validation performed for this audit pass:

- `gofmt` on:
  - `agent/internal/api/firewall_handlers.go`
  - `agent/internal/api/fail2ban_handlers.go`
  - `agent/internal/api/remote_backup_handlers.go`

## Current Audit Pass 5

This audit pass addressed mailbox secret handling, node deletion safety, and a few remaining admin integrity issues:

- Disabled automatic webmail SSO in `panel/app/Http/Controllers/WebmailController.php` instead of decrypting and reusing stored mailbox passwords.
- Removed mailbox password escrow from mailbox create and password-change flows in:
  - `panel/app/Http/Controllers/User/EmailController.php`
  - `panel/app/Http/Controllers/Admin/EmailController.php`
- Replaced the mailbox "Open Webmail" actions with direct links to the normal webmail login page in:
  - `panel/resources/js/Pages/User/Email/Index.vue`
  - `panel/resources/js/Pages/Admin/Email/DomainEmail.vue`
- Added a schema cleanup migration to drop the unused `password_encrypted` column from `email_accounts` in `panel/database/migrations/2026_04_06_000001_drop_password_encrypted_from_email_accounts.php`.
- Blocked node deletion when related resources still exist and added graceful foreign-key failure handling in `panel/app/Http/Controllers/Admin/NodeController.php`.
- Fixed firewall add/delete controller error handling so agent-side plain-text failures are returned as panel JSON error messages in `panel/app/Http/Controllers/Admin/SecurityController.php`.
- Limited service-action audit log entries to successful agent operations in `panel/app/Http/Controllers/Admin/NodeStatusController.php`.

## Current Audit Pass 6

This audit pass addressed the next agent-upgrade and backup-delivery issues:

- Restricted the agent self-upgrade endpoint to trusted HTTPS GitHub release URLs under the Strata Panel repository in `agent/internal/api/handlers.go`.
- Changed user backup downloads to fetch the archive through the authenticated panel-to-agent client instead of redirecting the browser to an unauthenticated raw agent URL in:
  - `panel/app/Services/AgentClient.php`
  - `panel/app/Http/Controllers/User/BackupController.php`
- Recorded remote backup push failures on completed backup jobs and surfaced a panel error when one or more off-site destination uploads fail in `panel/app/Http/Controllers/User/BackupController.php`.

## Current Audit Pass 7

This audit pass addressed the next admin restore, update, and audit-log issues:

- Changed admin backup restore to target the backup job's recorded node instead of the account's current node in `panel/app/Http/Controllers/Admin/BackupController.php`.
- Removed `--with-new-pkgs` from the agent OS update apply command so the backend now matches the UI's in-place upgrade contract in `agent/internal/api/update_handlers.go`.
- Hardened admin update apply error handling so plain-text agent failures are returned as structured JSON in `panel/app/Http/Controllers/Admin/UpdateController.php`.
- Delayed admin FTP create/delete audit log writes until the underlying agent action succeeds in `panel/app/Http/Controllers/Admin/FtpController.php`.
- Updated the admin updates page copy to match the backend behavior in `panel/resources/js/Pages/Admin/Updates/Index.vue`.

Validation performed for this audit pass:

- `gofmt` on:
  - `agent/internal/api/update_handlers.go`
- PHP lint on:
  - `panel/app/Http/Controllers/Admin/BackupController.php`
  - `panel/app/Http/Controllers/Admin/UpdateController.php`
  - `panel/app/Http/Controllers/Admin/FtpController.php`

## Current Audit Pass 8

This audit pass addressed remaining admin control-plane integrity and audit-trail issues:

- Rolled back newly created `users` and `accounts` rows when admin account provisioning fails in `panel/app/Http/Controllers/Admin/AccountController.php`.
- Delayed admin account create/delete audit logs until the underlying provisioning or deprovisioning action succeeds in `panel/app/Http/Controllers/Admin/AccountController.php`.
- Delayed admin database create/delete audit logs until the underlying agent action succeeds in `panel/app/Http/Controllers/Admin/DatabaseController.php`.
- Delayed hosted and standalone DNS audit logs until record or zone changes actually succeed in:
  - `panel/app/Http/Controllers/Admin/DnsController.php`
  - `panel/app/Http/Controllers/Admin/StandaloneDnsController.php`

Validation performed for this audit pass:

- PHP lint intended on:
  - `panel/app/Http/Controllers/Admin/AccountController.php`
  - `panel/app/Http/Controllers/Admin/DatabaseController.php`
  - `panel/app/Http/Controllers/Admin/DnsController.php`
  - `panel/app/Http/Controllers/Admin/StandaloneDnsController.php`

## Current Audit Pass 9

This audit pass closed the remaining reseller, API, and admin false-success paths found in the final sweep:

- Rolled back newly created reseller `user` and `account` rows when provisioning fails and delayed reseller account create/delete audit logs until success in `panel/app/Http/Controllers/Reseller/AccountController.php`.
- Delayed API account termination audit logs until remote deprovision succeeds in `panel/app/Http/Controllers/Api/V1/AccountController.php`.
- Rolled back locally created admin domains when provisioning fails and delayed admin domain create/SSL/delete audit logs until success in `panel/app/Http/Controllers/Admin/DomainController.php`.
- Delayed node deletion audit logging until the delete actually completes in `panel/app/Http/Controllers/Admin/NodeController.php`.

Validation performed for this audit pass:

- PHP lint intended on:
  - `panel/app/Http/Controllers/Reseller/AccountController.php`
  - `panel/app/Http/Controllers/Api/V1/AccountController.php`
  - `panel/app/Http/Controllers/Admin/DomainController.php`
  - `panel/app/Http/Controllers/Admin/NodeController.php`
