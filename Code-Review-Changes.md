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
