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
