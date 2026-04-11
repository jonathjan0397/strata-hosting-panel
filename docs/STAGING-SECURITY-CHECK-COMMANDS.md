# Staging Security Check Commands

Last updated: 2026-04-10
Use with:
- [STAGING-SECURITY-VALIDATION.md](/C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/docs/STAGING-SECURITY-VALIDATION.md)
- [STAGING-SECURITY-EVIDENCE-TEMPLATE.md](/C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/docs/STAGING-SECURITY-EVIDENCE-TEMPLATE.md)

This is the concrete command reference for the staging security run. Adjust hostnames, domains, and credentials for the environment under test.

## 1. Install And Upgrade Validation

Panel bootstrap:

```bash
cd /opt/strata-panel/panel
php artisan about
php artisan --version
php artisan route:list | head
```

Rollback permission checks:

```bash
ls -ld /opt/strata-panel-backups
ls -ldt /opt/strata-panel-backups/* | head -n 5
latest="$(ls -dt /opt/strata-panel-backups/* | head -n 1)"
ls -ld "$latest"
ls -l "$latest/metadata.json"
```

Upgrade utility presence:

```bash
which strata-upgrade
ls -l /usr/sbin/strata-upgrade
```

## 2. Authentication And Session Checks

Login throttling:

```bash
curl -I https://panel.example.com/login
```

Browser checks:
- perform 5 failed password attempts for one account from one IP
- perform 5 failed 2FA attempts for one account from one IP
- capture the lockout response and timing

Session cookie inspection:

```bash
curl -k -I https://panel.example.com/login
```

Verify:
- `Set-Cookie` includes `HttpOnly`
- `Secure` is present on HTTPS
- `SameSite=Lax` unless intentionally changed

## 3. Authorization And Tenant Isolation Checks

Reseller search isolation:
- log in as reseller
- search for usernames/emails belonging to another reseller or direct admin-owned account
- capture resulting list or denial

Direct object access checks:
- change IDs in browser URLs for:
  - domains
  - mailboxes
  - databases
  - backups
  - FTP accounts
- capture `403` or `404`

Audit trail check:

```bash
cd /opt/strata-panel/panel
php artisan tinker --execute="echo \App\Models\AuditLog::latest()->limit(10)->pluck('action')->implode(PHP_EOL);"
```

## 4. Service Exposure And Transport Checks

Panel headers:

```bash
curl -k -I https://panel.example.com
```

Verify:
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`
- `Cross-Origin-Opener-Policy: same-origin`
- `Strict-Transport-Security` on HTTPS

Unexpected ports:

```bash
ss -ltnp
ufw status verbose
```

Browser shell default-off:
- confirm shell links are absent in admin node pages unless explicitly enabled
- if intentionally enabled for test, confirm production requires explicit override

## 5. Mail, DNS, Backup, And File Safety Checks

Mail service checks:

```bash
openssl s_client -connect mail.example.com:993 -servername mail.example.com </dev/null
openssl s_client -starttls smtp -connect mail.example.com:587 -servername mail.example.com </dev/null
```

DNS checks:

```bash
dig +short mx example.com
dig +short txt example.com
dig +short txt _dmarc.example.com
dig +short txt default._domainkey.example.com
```

Backup checks:

```bash
cd /opt/strata-panel/panel
php artisan queue:failed
ls -lah /var/backups/strata
```

Node and agent health:

```bash
curl -k https://node1.example.com:8743/v1/health
systemctl status strata-agent --no-pager
systemctl status strata-webdav --no-pager
```

FTP/WebDAV checks:
- verify login with account-scoped credentials
- verify access remains inside the correct account home
- attempt path traversal through client or mounted path and capture the result

## 6. Browser And UX Gate

Per-role checks:
- log in as `admin`, `reseller`, and `user`
- capture nav screenshots
- open browser devtools console and verify no runtime errors

Admin nav expected groups:
- `Resellers`
- `Security`
- `System`
- `Infrastructure`
- `Hosting`

Updates page checks:
- current version shown
- latest published release shown
- advanced sources shown
- progress/log viewer functional

## 7. External Scan Pass

TLS:

```bash
curl -k -I https://panel.example.com
curl -k -I https://webmail.example.com
```

Mail:

```bash
openssl s_client -connect mail.example.com:465 -servername mail.example.com </dev/null
openssl s_client -starttls smtp -connect mail.example.com:587 -servername mail.example.com </dev/null
```

DNS:

```bash
dig ns example.com
dig soa example.com
dig mx example.com
```

Web headers:

```bash
curl -k -I https://panel.example.com
curl -k -I https://panel.example.com/login
```

## 8. Evidence Packaging

Recommended evidence bundle:
- screenshots by role
- copied headers
- command output snippets
- upgrade and rollback log excerpts
- mail header samples showing DKIM/SPF/DMARC outcomes
- explicit list of findings and signoff decision
