# Strata Panel

[![License: MIT](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)
[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-☕-yellow?style=flat-square)](https://buymeacoffee.com/jonathan0397)

Open-source hosting control panel — Nginx/Apache, PHP multi-version, email, DNS, FTP, SSL, and more.

**License:** MIT &nbsp;·&nbsp; **Target OS:** Debian 11 / 12 / 13

---

## Live Demo

**[http://panel.stratadevplatform.com](http://panel.stratadevplatform.com)**

Click any account card on the login page to autofill credentials, or use them directly:

| Role | Email | Password |
|------|-------|----------|
| Admin | `demo-admin@example.com` | `DemoAdmin2026` |
| End User | `demo-user@example.com` | `DemoUser2026!` |

> The demo server resets periodically. Changes you make are not permanent.

---

## Stack

| Layer | Technology |
|---|---|
| Panel | Laravel 13 + Vue 3 + Inertia.js |
| Agent | Go binary (strata-agent) |
| Web | Nginx / Apache (per node) |
| PHP | PHP-FPM 8.1 / 8.2 / 8.3 |
| Mail | Postfix + Dovecot + Rspamd + OpenDKIM (2048-bit) |
| DNS | PowerDNS |
| SSL | acme.sh (Let's Encrypt / ZeroSSL) |
| FTP | Pure-FTPd |
| Database | MariaDB |

## Architecture

A single install gives you a fully functional hosting server. Remote nodes can be added at any time to scale horizontally. See [docs/PLAN.md](docs/PLAN.md) for the full project plan, feature list, and development phases.

## Quick Start

```bash
cd panel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Default admin login after seeding: `admin@localhost` / `ChangeMe123!`

## Contributing

Bug reports and pull requests are welcome. Please [open an issue](https://github.com/jonathjan0397/strata-panel/issues) before submitting large changes.
