# Security Production Readiness

Last updated: 2026-04-10
Current release line: `1.0.0-BETA-3.12`

This document is the production security gate for Strata Hosting Panel. Public beta is acceptable with known risk. Production release is blocked until the mandatory items below are satisfied or explicitly accepted as residual risk.

Execution runbook:

- [docs/STAGING-SECURITY-VALIDATION.md](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/docs/STAGING-SECURITY-VALIDATION.md)

## Current Assessment

Status: `Not yet production-ready`

Reasons:
- the panel can broker high-impact agent actions across nodes
- the agent exposes a broad root-level systems API
- the browser shell path still provides a root PTY on the node when explicitly enabled
- broader authorization and tenant-isolation review is still incomplete
- external staging validation is still outstanding

## Mandatory Release Gates

### 1. Authentication and Session Security
- Enforce 2FA for all panel users with privileged access.
- Add login rate limiting / lockout protection.
- Review session lifetime, remember-me behavior, and session invalidation on password reset / logout.
- Confirm password reset and recovery flows do not bypass 2FA expectations.

### 2. Authorization and Tenant Isolation
- Verify admin, reseller, and user route separation end to end.
- Test direct-object access across accounts, domains, mailboxes, databases, backups, and DNS zones.
- Review impersonation flows and ensure they are fully audited.
- Verify account-feature gates cannot be bypassed through alternate routes.

### 3. Agent Trust Boundary
- Review every agent endpoint as a root-equivalent operation.
- Verify HMAC auth is consistently applied to all non-shell agent routes.
- Minimize or constrain the shell feature before production.
- Review all panel-to-agent certificate and secret handling.
- Ensure compromised panel credentials cannot silently pivot into unrestricted root shells without additional controls.

### 4. Command Execution and File Safety
- Review all command execution paths in panel, agent, installer, and updater.
- Confirm all file operations are jailed to intended account roots.
- Confirm backup restore, file upload, archive extract, and app install paths resist traversal and overwrite abuse.
- Verify service/log allowlists are narrow and enforced.

### 5. Upgrade and Rollback Security
- Verify release source trust and allowed channels.
- Ensure upgrade utility cannot be abused with arbitrary local file paths from the web UI.
- Confirm rollback backups have correct permissions and do not expose secrets broadly.
- Enforce release-only deployment for production systems.

### 6. Network and Service Hardening
- Validate TLS configuration for panel, agent, mail, webmail, phpMyAdmin, FTP/WebDAV.
- Confirm firewall defaults, fail2ban defaults, and exposed ports are intentional.
- Review unnecessary service exposure and disable what is not required.
- Review default security headers and proxy trust configuration.

### 7. Secrets and Sensitive Data
- Review where node HMAC secrets, API tokens, backup credentials, mail credentials, and private keys are stored.
- Confirm secrets are never written to logs, exports, browser payloads, or backup artifacts unintentionally.
- Ensure filesystem permissions on secret material are restrictive.

### 8. Monitoring and Recovery
- Alert on failed logins, node offline state, certificate expiry, backup failure, DNS drift, and upgrade failure.
- Validate restore procedures, not just backup creation.
- Document incident response and key rotation procedures.

## Confirmed Findings

### Medium: Reseller account search could leak non-owned accounts

Files:
- [panel/app/Http/Controllers/Reseller/AccountController.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Http/Controllers/Reseller/AccountController.php)

What was happening:
- reseller account listing first scoped to reseller-owned client accounts with `whereIn('user_id', $clientIds)`
- the search filter then appended `where username like ... OR whereHas(user.email like ...)` without grouping
- that SQL shape can let the `OR` side escape the reseller-owned scope and return accounts the reseller does not own when email matches

Current branch status:
- fixed by grouping the username/email search conditions inside a nested `where (...)`

Why it matters:
- this is direct tenant-isolation leakage in a reseller surface
- even if limited to list exposure, it violates the ownership boundary

Related correctness cleanup:
- the same grouped-search pattern was also applied to admin account and reseller listing pages to avoid inconsistent query behavior

### High: Browser shell opens a root PTY on the node when enabled

Files:
- [panel/app/Http/Controllers/Admin/ShellController.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Http/Controllers/Admin/ShellController.php)
- [agent/internal/api/shell_handler.go](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/agent/internal/api/shell_handler.go)
- [panel/config/strata.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/config/strata.php)

What happens:
- the panel builds a short-lived token and WebSocket URL for the node shell
- the agent validates the token and then starts `/bin/bash` with `HOME=/root`
- the PTY is a root shell
- browser shell is now disabled by default through `STRATA_BROWSER_SHELL_ENABLED=false`

Why this blocks production:
- this is an extremely high-impact feature
- enabling it in production without further hardening still creates a root-shell pivot from the panel
- there is no additional step-up auth or command restriction once the shell opens

Minimum remediation:
- either disable browser shell for production by default
- or restrict it behind explicit hardening controls:
  - step-up reauthentication
  - shorter one-time tokens
  - explicit audit trail
  - optional IP restriction
  - optional non-root shell mode

### Medium: Node HMAC secret was exposed in the admin node details page

Files:
- [panel/app/Http/Controllers/Admin/NodeController.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Http/Controllers/Admin/NodeController.php)
- [panel/resources/js/Pages/Admin/Nodes/Show.vue](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/resources/js/Pages/Admin/Nodes/Show.vue)

What was happening:
- the node details page returned the raw persistent `hmac_secret` as `installSecret`
- the frontend used it to render a ready-to-run install command in the browser

Why it matters:
- the node HMAC secret is the shared trust anchor for panel-to-agent operations
- exposing it to the browser unnecessarily widens the impact of XSS, session theft, or client-side logging

Current branch status:
- fixed by removing the plaintext secret from the Inertia payload
- the install command now requires the operator to supply the node secret from a secure source instead of receiving it in-page

### Medium: Rollback backups contain secrets and must be root-only

Files:
- [installer/upgrade.sh](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/installer/upgrade.sh)

What was happening:
- rollback backups include the full panel tree, including `.env` and other runtime secrets
- the upgrade path created backup directories without an explicit restrictive permission step

Why it matters:
- rollback backups are operationally necessary, but they are secret-bearing artifacts
- if their directory permissions drift too open, they become a high-value local disclosure target

Current branch status:
- hardened by explicitly setting `/opt/strata-panel-backups` and each new backup directory to `0700`
- backup metadata files are now written with `0600`

### Medium: License sync posts the installation secret to the license server

Files:
- [panel/app/Services/StrataLicense.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Services/StrataLicense.php)
- [panel/config/strata.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/config/strata.php)

What is happening:
- the panel sends both `install_token` and `install_secret` in the outbound license sync request body
- the same `install_secret` is also used locally to verify the response signature from the license server

Why it matters:
- the installation secret is acting as both a client secret and a response-verification secret
- transmitting it to the server weakens the separation of duties you normally want for a verifier secret
- if the license endpoint, transport logging, or receiving side is mishandled, this secret can be exposed unnecessarily

Current branch status:
- not remediated in this branch

Recommended remediation:
- change the license protocol so the server identifies the install by `install_token`
- authenticate requests with an HMAC or signed nonce derived from `install_secret`
- keep `install_secret` local and use it only for signing or verification, not as a plaintext request field

### Resolved in current branch: Login and 2FA throttling added

Files:
- [panel/app/Http/Controllers/Auth/AuthenticatedSessionController.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Http/Controllers/Auth/AuthenticatedSessionController.php)
- [panel/app/Http/Controllers/Auth/TwoFactorChallengeController.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Http/Controllers/Auth/TwoFactorChallengeController.php)

What changed:
- login attempts are now rate-limited per email/IP pair
- 2FA verification attempts are now rate-limited per user/IP pair
- successful login and successful 2FA clear the throttle state

### Resolved in current branch: Baseline proxy trust and browser security headers added

Files:
- [panel/app/Http/Middleware/TrustProxies.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Http/Middleware/TrustProxies.php)
- [panel/app/Http/Middleware/SecurityHeaders.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Http/Middleware/SecurityHeaders.php)
- [panel/bootstrap/app.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/bootstrap/app.php)
- [panel/config/session.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/config/session.php)

What changed:
- explicit proxy trust support is now available through `TRUSTED_PROXIES`
- the web stack now sets baseline security headers:
  - `X-Frame-Options: SAMEORIGIN`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy`
  - `Cross-Origin-Opener-Policy: same-origin`
  - `Strict-Transport-Security` on secure requests
- session cookies now default to secure in production unless explicitly overridden

## Positive Controls Already Present

### Two-factor enforcement exists globally in web middleware

Files:
- [panel/bootstrap/app.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/bootstrap/app.php)
- [panel/app/Http/Middleware/EnsureTwoFactorAuthenticated.php](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/panel/app/Http/Middleware/EnsureTwoFactorAuthenticated.php)

What is good:
- the web middleware stack appends two-factor enforcement globally
- confirmed users with 2FA enabled must complete the challenge before normal web access continues

### Agent API uses HMAC middleware for non-shell routes

Files:
- [agent/cmd/strata-agent/app/app.go](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/agent/cmd/strata-agent/app/app.go)
- [agent/internal/api/hmac.go](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/agent/internal/api/hmac.go)

What is good:
- `/v1` management routes are mounted behind HMAC authentication
- timestamps are validated with skew limits
- request bodies are signed

### Service and log operations are allowlisted

Files:
- [agent/internal/system/controls.go](C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/agent/internal/system/controls.go)
- [agent/internal/system/logs.go](C:/Users/Jcovington/Desktop/Strata%20Hosting Panel -Audit/agent/internal/system/logs.go)

What is good:
- service actions are limited to an explicit allowlist
- log reads are limited to an explicit map under `/var/log`

## Next Review Order

1. Browser shell hardening or production disablement policy
2. Route-by-route authorization review for admin/reseller/user
3. File manager, backup restore, and app installer path safety review
4. Upgrade utility and release-source trust review
5. Secret storage and protocol review
6. External staging scan and penetration test pass

## Production Exit Criteria

Do not label Strata as production-ready until:
- the high-severity shell risk is addressed or intentionally disabled for production
- authorization and tenant-isolation review is complete
- long-lived shared secrets are no longer exposed in browser payloads, overly broad backups, or plaintext integration protocols without explicit justification
- fresh install, upgrade, rollback, restore, and backup restore drills pass on clean staging systems
- an external security validation pass is completed and major findings are resolved
