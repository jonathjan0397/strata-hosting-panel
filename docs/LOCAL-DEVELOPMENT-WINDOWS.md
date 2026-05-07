# Local Development on Windows

This repository includes a Windows bootstrap script for local panel development:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap-local-windows.ps1
```

The script prepares a local Laravel and frontend environment inside the repo by:

- preferring the repo-local PHP runtime at `.tools/php83/php.exe` when available
- installing Composer dependencies in `panel/`
- installing npm dependencies in `panel/`
- creating `panel/.env` if missing
- creating `panel/database/database.sqlite`
- setting local SQLite, file cache, file session, and log mail defaults
- generating the Laravel app key
- clearing Laravel caches
- running migrations
- building frontend assets
- validating the troubleshooting routes with Artisan

## Recommended usage

From the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap-local-windows.ps1
```

If you need to fully refresh dependencies first:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap-local-windows.ps1 -ResetVendor -ResetNodeModules
```

## Start the panel locally

After bootstrap completes:

```powershell
cd .\panel
..\.tools\php83\php.exe artisan serve
npm.cmd run dev
```

If you prefer to stay in the repo root, use:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap-local-windows.ps1 -SkipComposer -SkipNpm -SkipMigrate
```

That revalidates the existing local setup without reinstalling dependencies.

## Common Windows failures

### `php.exe` Access is denied

If PHP was installed through a protected WinGet path and Windows blocks execution, use the repo-local runtime under:

```text
.tools\php83\php.exe
```

The bootstrap script now prefers that local binary automatically.

### `npm install` fails with `EPERM` or `EACCES`

Typical causes are file locks, antivirus, or an editor watching `node_modules`.

Try:

1. close editors, terminals, and dev servers using the repo
2. temporarily exclude the repo from antivirus scanning
3. rerun bootstrap with `-ResetNodeModules`

### Artisan fails on first boot

Check:

- `panel/vendor` exists
- `panel/.env` exists
- `panel/database/database.sqlite` exists
- the bootstrap script completed without errors

If needed, rerun:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap-local-windows.ps1
```

## Notes

- The repo-local PHP runtime is intended for development convenience on Windows.
- Production installs still use the Debian installer and server-side PHP packages.
