param(
  [Parameter(Mandatory = $true)][string]$BackupZip
)

$ErrorActionPreference = "Stop"
$backendDir = Split-Path -Path $PSScriptRoot -Parent
if (-not (Test-Path $BackupZip)) {
  throw "Backup zip not found: $BackupZip"
}

$tmpDir = Join-Path $env:TEMP ("dms-restore-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force -Path $tmpDir | Out-Null
Expand-Archive -Path $BackupZip -DestinationPath $tmpDir -Force

$snapshotEnv = Join-Path $tmpDir ".env.snapshot"
if (Test-Path $snapshotEnv) {
  Copy-Item $snapshotEnv (Join-Path $backendDir ".env") -Force
}

$sqliteSnapshot = Join-Path $tmpDir "database.sqlite"
if (Test-Path $sqliteSnapshot) {
  Copy-Item $sqliteSnapshot (Join-Path $backendDir "database\database.sqlite") -Force
}

$storageSnapshot = Join-Path $tmpDir "storage-app"
if (Test-Path $storageSnapshot) {
  Copy-Item (Join-Path $storageSnapshot "*") (Join-Path $backendDir "storage\app") -Recurse -Force
}

Write-Host "Restore completed from $BackupZip"
