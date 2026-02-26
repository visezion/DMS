param(
  [string]$ServiceName = "DMSAgent",
  [string]$InstallDir = "C:\Program Files\DMS Agent",
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

$serviceCandidates = @($ServiceName, "DMS Agent") | Select-Object -Unique
foreach ($svc in $serviceCandidates) {
  $existing = Get-Service -Name $svc -ErrorAction SilentlyContinue
  if ($null -ne $existing) {
    try {
      if ($existing.Status -ne 'Stopped') {
        Stop-Service -Name $svc -Force -ErrorAction SilentlyContinue
      }
    }
    catch {
      # Continue with sc delete fallback.
    }
    sc.exe delete $svc | Out-Null
    Start-Sleep -Seconds 1
  }
}

function Invoke-ScOrThrow {
  param(
    [Parameter(Mandatory = $true)][string[]]$Arguments,
    [Parameter(Mandatory = $true)][string]$ErrorMessage
  )

  & sc.exe @Arguments | Out-Null
  if ($LASTEXITCODE -ne 0) {
    throw "$ErrorMessage (exit code $LASTEXITCODE)"
  }
}

New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
Copy-Item -Path (Join-Path $SourceDir "*") -Destination $InstallDir -Recurse -Force

$ExePath = Join-Path $InstallDir "Dms.Agent.Service.exe"
if (-not (Test-Path -Path $ExePath -PathType Leaf)) {
  throw "Agent executable not found after copy: $ExePath"
}

Invoke-ScOrThrow -Arguments @("create", $ServiceName, "binPath=", "`"$ExePath`"", "start=", "auto") -ErrorMessage "Failed to create service $ServiceName"
Invoke-ScOrThrow -Arguments @("description", $ServiceName, "DMS endpoint management agent") -ErrorMessage "Failed to set service description for $ServiceName"

# Hardening for reliability: start on boot and restart automatically after failures.
Invoke-ScOrThrow -Arguments @("config", $ServiceName, "start=", "delayed-auto") -ErrorMessage "Failed to configure delayed auto-start for $ServiceName"
Invoke-ScOrThrow -Arguments @("failure", $ServiceName, "reset=", "86400", "actions=", "restart/60000/restart/120000/restart/300000") -ErrorMessage "Failed to set service recovery actions for $ServiceName"
Invoke-ScOrThrow -Arguments @("failureflag", $ServiceName, "1") -ErrorMessage "Failed to set service failure flag for $ServiceName"

Invoke-ScOrThrow -Arguments @("start", $ServiceName) -ErrorMessage "Failed to start service $ServiceName"

Write-Host "Service $ServiceName installed and started from $ExePath with auto-recovery enabled"
