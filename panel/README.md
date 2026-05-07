# Strata Hosting Panel — Laravel Application

[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-☕-yellow?style=flat-square)](https://buymeacoffee.com/jonathan0397)

This directory contains the main Laravel + Vue 3 + Inertia.js panel application.

## Requirements

- PHP 8.3+
- Composer
- Node.js 20+
- MariaDB 10.6+
- Redis

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run dev
```

## Production

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm run build
php artisan config:cache
php artisan route:cache
```

See the [project plan](../docs/PLAN.md) for the full architecture and development roadmap.
