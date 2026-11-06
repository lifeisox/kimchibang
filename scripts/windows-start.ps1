$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker Desktop is required. Install it and restart Windows first.' }
docker info --format '{{.ServerVersion}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Open Docker Desktop and wait until the engine is running.' }
function New-LocalPassword {
    $bytes = New-Object byte[] 24
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
}
$utf8 = New-Object System.Text.UTF8Encoding($false)
if (-not (Test-Path '.env')) {
    $config = "DB_PASSWORD=$(New-LocalPassword)`nDB_ROOT_PASSWORD=$(New-LocalPassword)`n"
    [IO.File]::WriteAllText((Join-Path $PWD '.env'), $config, $utf8)
}
New-Item -ItemType Directory -Force '.local' | Out-Null
if (-not (Test-Path '.local/admin-password.txt')) {
    [IO.File]::WriteAllText((Join-Path $PWD '.local/admin-password.txt'), (New-LocalPassword), $utf8)
}
docker compose up -d --build
if ($LASTEXITCODE -ne 0) { throw 'Could not start the app. Check Docker Desktop and try again.' }
docker compose exec -T web php scripts/init.php
if ($LASTEXITCODE -ne 0) { throw 'Database setup did not finish. Please report the error.' }
Get-Content -Raw '.local/admin-password.txt' | docker compose exec -T web php scripts/create-user.php admin --if-missing
if ($LASTEXITCODE -ne 0) { throw 'Could not prepare the admin account.' }
Start-Process 'http://localhost:8080'
Start-Process notepad.exe -ArgumentList (Join-Path $PWD '.local/admin-password.txt')
Write-Host 'Ready. Username: admin. The local password file is open in Notepad.'
