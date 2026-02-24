param(
  [string]$OutDir = ""
)

$ErrorActionPreference = "Stop"
$backendDir = Split-Path -Path $PSScriptRoot -Parent
if ([string]::IsNullOrWhiteSpace($OutDir)) {
  $OutDir = Join-Path $backendDir "storage\backups"
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$bundleDir = Join-Path $OutDir "backup-$stamp"
New-Item -ItemType Directory -Force -Path $bundleDir | Out-Null

$envFile = Join-Path $backendDir ".env"
Copy-Item $envFile (Join-Path $bundleDir ".env.snapshot") -Force

$sqlitePath = Join-Path $backendDir "database\database.sqlite"
if (Test-Path $sqlitePath) {
  Copy-Item $sqlitePath (Join-Path $bundleDir "database.sqlite") -Force
}

$storageApp = Join-Path $backendDir "storage\app"
if (Test-Path $storageApp) {
  Copy-Item $storageApp (Join-Path $bundleDir "storage-app") -Recurse -Force
}

$zipPath = Join-Path $OutDir "backup-$stamp.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Compress-Archive -Path (Join-Path $bundleDir "*") -DestinationPath $zipPath

Write-Host "Backup created: $zipPath"
