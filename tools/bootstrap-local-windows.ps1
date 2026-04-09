param(
    [switch]$ResetNodeModules,
    [switch]$ResetVendor,
    [switch]$SkipNpm,
    [switch]$SkipComposer,
    [switch]$SkipMigrate
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$panelPath = Join-Path $repoRoot 'panel'
$envPath = Join-Path $panelPath '.env'
$envExamplePath = Join-Path $panelPath '.env.example'
$sqlitePath = Join-Path $panelPath 'database\database.sqlite'
$composerPhar = Join-Path $repoRoot '.tools\composer.phar'
$localPhpPath = Join-Path $repoRoot '.tools\php83\php.exe'

function Step($message) {
    Write-Host ""
    Write-Host "==> $message" -ForegroundColor Cyan
}

function Info($message) {
    Write-Host "    $message"
}

function Fail($message) {
    Write-Host ""
    Write-Host "[fail] $message" -ForegroundColor Red
    exit 1
}

function Require-Command($name, $helpText) {
    $cmd = Get-Command $name -ErrorAction SilentlyContinue
    if (-not $cmd) {
        Fail "$name is not available. $helpText"
    }

    return $cmd.Source
}

function Run-Step {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [string[]]$ArgumentList = @(),
        [string]$WorkingDirectory = $repoRoot
    )

    $quotedArgs = @($ArgumentList | ForEach-Object {
        if ($_ -match '\s') {
            '"' + ($_ -replace '"', '\"') + '"'
        } else {
            $_
        }
    })
    $argText = if ($quotedArgs.Count -gt 0) { ' ' + ($quotedArgs -join ' ') } else { '' }
    Write-Host "    > $FilePath$argText" -ForegroundColor DarkGray

    $process = Start-Process -FilePath $FilePath -ArgumentList $quotedArgs -WorkingDirectory $WorkingDirectory -NoNewWindow -Wait -PassThru
    if ($process.ExitCode -ne 0) {
        Fail "Command failed with exit code $($process.ExitCode): $FilePath$argText"
    }
}

function Ensure-EnvValue {
    param(
        [Parameter(Mandatory = $true)][string]$Key,
        [Parameter(Mandatory = $true)][string]$Value
    )

    $content = if (Test-Path $envPath) {
        Get-Content $envPath -Raw
    } else {
        ''
    }

    $escapedKey = [regex]::Escape($Key)
    $line = "$Key=$Value"

    if ($content -match "(?m)^$escapedKey=") {
        $content = [regex]::Replace($content, "(?m)^$escapedKey=.*$", $line)
    } else {
        if ($content -and -not $content.EndsWith("`n")) {
            $content += "`r`n"
        }
        $content += "$line`r`n"
    }

    Set-Content -Path $envPath -Value $content -Encoding UTF8
}

function Ensure-PhpIniValue {
    param(
        [Parameter(Mandatory = $true)][string]$PhpIniPath,
        [Parameter(Mandatory = $true)][string]$Pattern,
        [Parameter(Mandatory = $true)][string]$Replacement
    )

    $content = Get-Content -Path $PhpIniPath -Raw
    if ($content -match $Pattern) {
        $content = [regex]::Replace($content, $Pattern, $Replacement, [System.Text.RegularExpressions.RegexOptions]::Multiline)
    } else {
        if ($content -and -not $content.EndsWith("`n")) {
            $content += "`r`n"
        }
        $content += "$Replacement`r`n"
    }

    Set-Content -Path $PhpIniPath -Value $content -Encoding UTF8
}

function Enable-PhpExtension {
    param(
        [Parameter(Mandatory = $true)][string]$PhpIniPath,
        [Parameter(Mandatory = $true)][string]$Extension
    )

    Ensure-PhpIniValue -PhpIniPath $PhpIniPath -Pattern "(?m)^;?extension=$([regex]::Escape($Extension))(?:\.dll)?\s*$" -Replacement "extension=$Extension"
}

function Normalize-PhpIniExtensions {
    param(
        [Parameter(Mandatory = $true)][string]$PhpIniPath,
        [Parameter(Mandatory = $true)][string[]]$Extensions
    )

    $content = Get-Content -Path $PhpIniPath -Raw
    $normalizedExtensions = $Extensions | ForEach-Object { $_.ToLowerInvariant() }

    $lines = $content -split "\r?\n"
    $seenExtensionDir = $false
    $seenExtensions = @{}
    $cleanLines = foreach ($line in $lines) {
        if ($line -match '^\s*extension_dir\s*=') {
            if ($seenExtensionDir) {
                continue
            }

            $seenExtensionDir = $true
            'extension_dir = "ext"'
            continue
        }

        if ($line -match '^\s*extension=([A-Za-z0-9_]+)(?:\.dll)?\s*$') {
            $extensionName = $Matches[1].ToLowerInvariant()
            if ($normalizedExtensions -contains $extensionName) {
                if ($seenExtensions.ContainsKey($extensionName)) {
                    continue
                }

                $seenExtensions[$extensionName] = $true
                "extension=$extensionName"
                continue
            }
        }

        $line
    }

    Set-Content -Path $PhpIniPath -Value ($cleanLines -join "`r`n") -Encoding UTF8
}

if (-not (Test-Path $panelPath)) {
    Fail "Could not find the panel directory at $panelPath"
}

Step "Checking required tools"
$phpPath = if (Test-Path $localPhpPath) {
    $localPhpPath
} else {
    Require-Command 'php' 'Install PHP and ensure php.exe is on PATH, or run this script from a shell where PHP is available.'
}
$npmPath = $null
if (-not $SkipNpm) {
    $npmPath = Require-Command 'npm.cmd' 'Install Node.js from https://nodejs.org/ and ensure npm.cmd is on PATH.'
}

$composerCommand = Get-Command composer -ErrorAction SilentlyContinue
if (-not $SkipComposer -and -not $composerCommand -and -not (Test-Path $composerPhar)) {
    Fail "Composer is not available. Install Composer globally or place composer.phar at $composerPhar"
}

Info "PHP: $phpPath"
if ($npmPath) {
    Info "npm: $npmPath"
}
if ($composerCommand) {
    Info "Composer: $($composerCommand.Source)"
} elseif (-not $SkipComposer) {
    Info "Composer: $composerPhar"
}

Step "Validating PHP runtime"
Run-Step -FilePath $phpPath -ArgumentList @('-v')

$phpIniPath = Join-Path (Split-Path -Parent $phpPath) 'php.ini'
if (Test-Path $phpIniPath) {
    Step "Configuring PHP extensions"
    $requiredPhpExtensions = @('curl', 'fileinfo', 'intl', 'mbstring', 'mysqli', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'sqlite3', 'zip')
    Ensure-PhpIniValue -PhpIniPath $phpIniPath -Pattern '(?m)^;?extension_dir\s*=.*$' -Replacement 'extension_dir = "ext"'
    foreach ($extension in $requiredPhpExtensions) {
        Enable-PhpExtension -PhpIniPath $phpIniPath -Extension $extension
    }
    Normalize-PhpIniExtensions -PhpIniPath $phpIniPath -Extensions $requiredPhpExtensions
}

if (-not $SkipComposer) {
    Step "Installing PHP dependencies"
    if ($ResetVendor) {
        $vendorPath = Join-Path $panelPath 'vendor'
        if (Test-Path $vendorPath) {
            Info "Removing vendor/"
            Remove-Item -Recurse -Force $vendorPath
        }
    }

    if ($composerCommand) {
        Run-Step -FilePath $composerCommand.Source -ArgumentList @('install', '--no-interaction', '--prefer-dist') -WorkingDirectory $panelPath
    } else {
        Run-Step -FilePath $phpPath -ArgumentList @($composerPhar, 'install', '--no-interaction', '--prefer-dist') -WorkingDirectory $panelPath
    }
}

if (-not $SkipNpm) {
    Step "Installing frontend dependencies"
    $nodeModulesPath = Join-Path $panelPath 'node_modules'
    $lockPath = Join-Path $panelPath 'package-lock.json'

    if ($ResetNodeModules) {
        if (Test-Path $nodeModulesPath) {
            Info "Removing node_modules/"
            Remove-Item -Recurse -Force $nodeModulesPath
        }
        if (Test-Path $lockPath) {
            Info "Removing package-lock.json"
            Remove-Item -Force $lockPath
        }
    }

    Run-Step -FilePath $npmPath -ArgumentList @('cache', 'clean', '--force') -WorkingDirectory $panelPath
    Run-Step -FilePath $npmPath -ArgumentList @('install') -WorkingDirectory $panelPath
}

Step "Preparing local Laravel environment"
if (-not (Test-Path $envPath)) {
    if (Test-Path $envExamplePath) {
        Copy-Item $envExamplePath $envPath
        Info "Created panel/.env from .env.example"
    } else {
        New-Item -ItemType File -Path $envPath | Out-Null
        Info "Created empty panel/.env"
    }
}

if (-not (Test-Path $sqlitePath)) {
    New-Item -ItemType File -Path $sqlitePath -Force | Out-Null
    Info "Created SQLite database file"
}

Ensure-EnvValue -Key 'APP_ENV' -Value 'local'
Ensure-EnvValue -Key 'APP_DEBUG' -Value 'true'
Ensure-EnvValue -Key 'DB_CONNECTION' -Value 'sqlite'
Ensure-EnvValue -Key 'DB_DATABASE' -Value 'database/database.sqlite'
Ensure-EnvValue -Key 'SESSION_DRIVER' -Value 'file'
Ensure-EnvValue -Key 'QUEUE_CONNECTION' -Value 'sync'
Ensure-EnvValue -Key 'CACHE_STORE' -Value 'file'
Ensure-EnvValue -Key 'MAIL_MAILER' -Value 'log'

Step "Generating application key"
Run-Step -FilePath $phpPath -ArgumentList @('artisan', 'key:generate', '--force') -WorkingDirectory $panelPath

Step "Clearing caches"
Run-Step -FilePath $phpPath -ArgumentList @('artisan', 'optimize:clear') -WorkingDirectory $panelPath

if (-not $SkipMigrate) {
    Step "Running migrations"
    Run-Step -FilePath $phpPath -ArgumentList @('artisan', 'migrate', '--force') -WorkingDirectory $panelPath
}

if (-not $SkipNpm) {
    Step "Building frontend assets"
    Run-Step -FilePath $npmPath -ArgumentList @('run', 'build') -WorkingDirectory $panelPath
}

Step "Running validation commands"
Run-Step -FilePath $phpPath -ArgumentList @('artisan', 'about') -WorkingDirectory $panelPath
Run-Step -FilePath $phpPath -ArgumentList @('artisan', 'route:list', '--name=troubleshooting') -WorkingDirectory $panelPath

Write-Host ""
Write-Host "[ok] Local environment is ready." -ForegroundColor Green
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "  1. cd `"$panelPath`""
Write-Host "  2. php artisan serve"
Write-Host "  3. npm.cmd run dev"
