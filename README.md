# Strata Panel

Open-source hosting control panel — Apache/Nginx, PHP multi-version, email, DNS, FTP, SSL.

**License:** MIT
**Target OS:** Debian 11/12

## Stack

| Layer | Technology |
|---|---|
| Panel | Laravel 12 + Vue 3 + Inertia.js |
| Queue | Redis + Laravel Horizon |
| Agent | Go binary (strata-agent) |
| Web | Nginx / Apache |
| PHP | PHP-FPM 8.1 / 8.2 / 8.3 |
| Mail | Postfix + Dovecot + Rspamd + OpenDKIM (2048-bit) |
| DNS | PowerDNS |
| SSL | acme.sh (Let's Encrypt / ZeroSSL) |
| FTP | Pure-FTPd |
| Database | MariaDB |

## Architecture

A single install gives you a fully functional hosting server. Remote nodes can be added at any time to scale horizontally.

## Development

See [docs/PLAN.md](docs/PLAN.md) for the full project plan and development phases.

```bash
# Panel setup
cd panel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run dev
```
