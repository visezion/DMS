param(
  [switch]$EnableWinRM = $true
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Set-DwordValue {
  param(
    [Parameter(Mandatory = $true)][string]$Path,
    [Parameter(Mandatory = $true)][string]$Name,
    [Parameter(Mandatory = $true)][int]$Value
  )

  if (-not (Test-Path -Path $Path)) {
    New-Item -Path $Path -Force | Out-Null
  }

  New-ItemProperty -Path $Path -Name $Name -PropertyType DWord -Value $Value -Force | Out-Null
}

Write-Host "Configuring host for one-time remote deployment bootstrap..."

# Required for local admin accounts to get elevated remote token in workgroup environments.
Set-DwordValue -Path "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System" -Name "LocalAccountTokenFilterPolicy" -Value 1

# Ensure admin shares are enabled.
Set-DwordValue -Path "HKLM:\SYSTEM\CurrentControlSet\Services\LanmanServer\Parameters" -Name "AutoShareWks" -Value 1

# Start required services.
$services = @("LanmanServer", "RemoteRegistry", "RpcSs")
foreach ($svc in $services) {
  try {
    Set-Service -Name $svc -StartupType Automatic -ErrorAction Stop
    Start-Service -Name $svc -ErrorAction SilentlyContinue
    Write-Host "Service ready: $svc"
  }
  catch {
    Write-Warning "Could not configure service $svc: $($_.Exception.Message)"
  }
}

# Open inbound firewall for SMB/RPC/WMI.
try {
  netsh advfirewall firewall set rule group="File and Printer Sharing" new enable=Yes | Out-Null
  netsh advfirewall firewall set rule group="Windows Management Instrumentation (WMI)" new enable=Yes | Out-Null
  netsh advfirewall firewall set rule group="Remote Service Management" new enable=Yes | Out-Null
  netsh advfirewall firewall set rule group="Remote Scheduled Tasks Management" new enable=Yes | Out-Null
  Write-Host "Firewall groups enabled for remote administration."
}
catch {
  Write-Warning "Firewall group enable may be partial: $($_.Exception.Message)"
}

if ($EnableWinRM) {
  try {
    Enable-PSRemoting -Force -SkipNetworkProfileCheck | Out-Null
    Write-Host "WinRM enabled."
  }
  catch {
    Write-Warning "WinRM enable failed: $($_.Exception.Message)"
  }
}

Write-Host ""
Write-Host "Bootstrap completed."
Write-Host "You can now retry remote install from admin PC."
