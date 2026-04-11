# Staging Security Evidence Template

Last updated: 2026-04-10
Use with: [STAGING-SECURITY-VALIDATION.md](/C:/Users/Jcovington/Desktop/Strata%20Hosting%20Panel%20-Audit/docs/STAGING-SECURITY-VALIDATION.md)

This template is for capturing the actual evidence from a staging security validation run. Fill it in during execution so release decisions are tied to concrete proof, not memory.

## Run Metadata

- Release under test:
- Git commit:
- Environment name:
- Test date:
- Executed by:
- Primary node:
- Child nodes:
- Notes:

## 1. Install And Upgrade Validation

- Fresh install completed:
- Installer showed expected release version:
- Panel accessible after install:
- Primary node online:
- Child node online:
- Upgrade from previous release succeeded:
- Rollback backup created:
- Rollback permissions verified:
- Rollback action tested:

Evidence:

- `php artisan about`:
- Update page screenshot:
- `ls -ld /opt/strata-panel-backups`:
- `ls -ld /opt/strata-panel-backups/<latest>`:
- `ls -l /opt/strata-panel-backups/<latest>/metadata.json`:
- Upgrade log excerpt:
- Rollback log excerpt:

## 2. Authentication And Session Checks

- Admin login verified:
- Reseller login verified:
- User login verified:
- Login throttling verified:
- 2FA throttling verified:
- Logout invalidated session:

Evidence:

- Login throttle output:
- 2FA throttle output:
- Session cookie observations:

## 3. Authorization And Tenant Isolation Checks

- Admin access behaves correctly:
- Reseller cannot access non-owned accounts:
- User cannot access another user's resources:
- Impersonation works only within allowed boundaries:
- Search results stay tenant-scoped:

Evidence:

- Reseller account search check:
- User resource isolation check:
- Impersonation audit entry:

## 4. Service Exposure And Transport Checks

- `TRUSTED_PROXIES` set correctly:
- Security headers present on web responses:
- Secure session cookie behavior verified:
- Browser shell hidden/blocked by default:
- No unexpected public management endpoints exposed:

Evidence:

- Response headers:
- Cookie attributes:
- Browser shell access result:

## 5. Mail, DNS, Backup, And File Safety Checks

- Mail login verified:
- Mail send verified:
- DKIM signing verified:
- DNS management operations scoped correctly:
- Backup create verified:
- Backup restore verified:
- File manager jailed correctly:
- FTP/WebDAV access scoped correctly:

Evidence:

- Mail send headers:
- DKIM evidence:
- DNS change record:
- Backup job log:
- Restore log:
- File access boundary test:

## 6. Browser And UX Gate

- Admin nav renders expected groups:
- Reseller nav renders expected groups:
- User nav renders expected groups:
- No browser console errors after login:
- Updates page log viewer works:

Evidence:

- Admin screenshot:
- Reseller screenshot:
- User screenshot:
- Console capture:

## 7. External Scan Pass

- TLS scan completed:
- Mail deliverability/config scan completed:
- DNS exposure scan completed:
- Basic web header scan completed:
- High findings:
- Medium findings:
- Low findings:

Evidence:

- TLS scan summary:
- Mail scan summary:
- DNS scan summary:
- Header scan summary:

## 8. Findings And Decisions

### Open Findings

- Severity:
  Area:
  Description:
  Owner:
  Resolution target:

### Accepted Risks

- Risk:
  Reason accepted:
  Expiration or review date:

### Release Decision

- Production ready: `yes/no`
- If no, blockers:
- If yes, approved by:
- Follow-up tasks:
