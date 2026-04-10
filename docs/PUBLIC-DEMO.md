# Public Demo / Smoke Server

There is no public demo environment available right now.

When a shared smoke-test instance is reintroduced, document these items here before publishing it:

- the live demo URL
- whether the demo uses shared credentials or invitation-only access
- what data is seeded into the instance
- reset cadence and acceptable use rules
- whether certificate warnings are expected during setup windows

Do not publish reusable login credentials in this repository unless the demo is intentionally public and actively maintained.

## Reset / Reseed

Run this on the smoke server after a fresh install or whenever demo state needs to be reset:

```bash
cd /opt/strata-panel/panel
php artisan demo:seed-public --domain=stratadevplatform.net --reset --provision
php artisan optimize:clear
```

Enable the login-page credential cards on the public demo host only if you intentionally want public shared access:

```bash
cd /opt/strata-panel/panel
grep -q '^STRATA_DEMO_MODE=' .env \
  && sed -i 's/^STRATA_DEMO_MODE=.*/STRATA_DEMO_MODE=true/' .env \
  || printf '\nSTRATA_DEMO_MODE=true\n' >> .env
php artisan optimize:clear
```

## Demo Safety Rules

- Do not use the public demo for private data, real domains, real mailbox credentials, or real customer records.
- Demo data can be deleted at any time.
- Treat every public demo account as fully shared when public credentials are enabled.
- Keep production installers and normal deployments with `STRATA_DEMO_MODE=false`.
