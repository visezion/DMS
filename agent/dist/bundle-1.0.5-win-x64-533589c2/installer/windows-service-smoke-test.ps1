param(
  [string]$SourceDir = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Path $PSCommandPath -Parent
if ([string]::IsNullOrWhiteSpace($SourceDir)) {
  $SourceDir = Join-Path (Split-Path -Path $scriptDir -Parent) "agent"
}

if (-not (Test-Path -Path $SourceDir -PathType Container)) {
  throw "Agent source directory not found: $SourceDir"
}

$exePath = Join-Path $SourceDir "Dms.Agent.Service.exe"
if (-not (Test-Path -Path $exePath -PathType Leaf)) {
  throw "Agent executable not found: $exePath"
}

$output = & $exePath --diagnostics 2>&1
$exitCode = $LASTEXITCODE

if ($output) {
  $output | Write-Output
}

if ($exitCode -ne 0) {
  throw "Agent smoke test failed with exit code $exitCode"
}

Write-Host "Agent smoke test passed for $SourceDir"
