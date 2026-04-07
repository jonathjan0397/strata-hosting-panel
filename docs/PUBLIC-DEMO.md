# Public Demo / Smoke Server

The public smoke-test instance is intended for community testing only. It may be reset without notice and should not be used to store real data.

Demo URL:

```text
https://stratadevplatform.net
```

The instance may use a self-signed certificate while DNS/ACME validation is being adjusted. Browser certificate warnings are expected in that state.

## Public Credentials

| Role | Email | Password |
| --- | --- | --- |
| Admin | `demo-admin@stratadevplatform.net` | `DemoAdmin2026!` |
| Reseller | `demo-reseller@stratadevplatform.net` | `DemoReseller2026!` |
| User | `demo-user@stratadevplatform.net` | `DemoUser2026!` |
| Reseller Client | `demo-client@stratadevplatform.net` | `DemoClient2026!` |

The installer-created private admin account may also exist on the smoke host, but it is not part of the public demo contract.

## Seeded Demo Data

The public demo seed creates:

- A demo admin, reseller, end-user, and reseller client.
- A reseller package and all-features feature list for demo accounts.
- A `demouser` hosting account with:
  - `demo-user.stratadevplatform.net`
  - `blog-demo.stratadevplatform.net`
  - matching A, CNAME, MX, SPF, and DMARC DNS records
  - `hello@demo-user.stratadevplatform.net`
  - `support@demo-user.stratadevplatform.net` forwarding to the demo mailbox
  - MySQL and PostgreSQL demo databases
- A `democlient` reseller-client hosting account with:
  - `client-demo.stratadevplatform.net`
  - matching A, CNAME, MX, SPF, and DMARC DNS records
  - `hello@client-demo.stratadevplatform.net`
  - `support@client-demo.stratadevplatform.net` forwarding to the demo mailbox
  - MySQL and PostgreSQL demo databases

## Reset / Reseed

Run this on the smoke server after a fresh install or whenever demo state needs to be reset:

```bash
cd /opt/strata-panel/panel
php artisan demo:seed-public --domain=stratadevplatform.net --reset --provision
php artisan optimize:clear
```

Enable the login-page credential cards on the public demo host:

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
- Treat every public demo account as fully shared.
- Keep production installers and normal deployments with `STRATA_DEMO_MODE=false`.
