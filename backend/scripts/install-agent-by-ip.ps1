param(
  [Parameter(Mandatory = $false)][string]$InstallScriptUrl,
  [Parameter(Mandatory = $false)][string]$TargetIp,
  [Parameter(Mandatory = $false)][string]$TargetListPath,
  [Parameter(Mandatory = $false)][string]$Username,
  [Parameter(Mandatory = $false)][string]$Password,
  [Parameter(Mandatory = $false)][int]$ThrottleLimit = 10,
  [Parameter(Mandatory = $false)][switch]$SkipCertCheck,
  [Parameter(Mandatory = $false)][switch]$WhatIf
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Read-Targets {
  param(
    [string]$SingleIp,
    [string]$ListPath
  )

  $targets = @()
  if (-not [string]::IsNullOrWhiteSpace($SingleIp)) {
    $targets += $SingleIp.Trim()
  }

  if (-not [string]::IsNullOrWhiteSpace($ListPath)) {
    if (-not (Test-Path -Path $ListPath -PathType Leaf)) {
      throw "Target list file not found: $ListPath"
    }

    $lines = Get-Content -Path $ListPath -ErrorAction Stop
    foreach ($line in $lines) {
      $value = $line.Trim()
      if ([string]::IsNullOrWhiteSpace($value)) { continue }
      if ($value.StartsWith("#")) { continue }
      $targets += $value
    }
  }

  $targets = @($targets | Sort-Object -Unique)
  if ($targets.Count -eq 0) {
    throw "No targets provided. Use -TargetIp or -TargetListPath."
  }

  return $targets
}

function Build-Credential {
  param(
    [string]$User,
    [string]$Pass
  )

  if ([string]::IsNullOrWhiteSpace($User) -or [string]::IsNullOrWhiteSpace($Pass)) {
    return $null
  }

  $secure = ConvertTo-SecureString $Pass -AsPlainText -Force
  return New-Object System.Management.Automation.PSCredential($User, $secure)
}

function New-SessionOptionOrNull {
  param([switch]$SkipChecks)

  if ($SkipChecks) {
    return New-PSSessionOption -SkipCACheck -SkipCNCheck -SkipRevocationCheck
  }
  return $null
}

if ([string]::IsNullOrWhiteSpace($InstallScriptUrl)) {
  throw "InstallScriptUrl is required."
}

$targets = @(Read-Targets -SingleIp $TargetIp -ListPath $TargetListPath)
$credential = Build-Credential -User $Username -Pass $Password
$sessionOption = New-SessionOptionOrNull -SkipChecks:$SkipCertCheck
$results = New-Object System.Collections.Generic.List[object]

Write-Host "Targets: $($targets.Count)"
Write-Host "WhatIf: $($WhatIf.IsPresent)"
Write-Host "Starting remote install..."

$scriptBlock = {
  param(
    [string]$RemoteInstallScriptUrl,
    [bool]$RemoteWhatIf
  )

  $ErrorActionPreference = "Stop"
  if ($RemoteWhatIf) {
    return @{
      ok = $true
      message = "WhatIf mode: skipped install command."
    }
  }

  $command = "iwr -useb '$RemoteInstallScriptUrl' | iex"
  powershell -ExecutionPolicy Bypass -Command $command
  return @{
    ok = $true
    message = "Install command invoked."
  }
}

foreach ($ip in $targets) {
  $started = Get-Date
  Write-Host "[$ip] connecting..."
  try {
    $invokeParams = @{
      ComputerName = $ip
      ScriptBlock = $scriptBlock
      ArgumentList = @($InstallScriptUrl, [bool]$WhatIf.IsPresent)
      ErrorAction = "Stop"
    }

    if ($null -ne $credential) {
      $invokeParams.Credential = $credential
    }
    if ($null -ne $sessionOption) {
      $invokeParams.SessionOption = $sessionOption
    }

    $response = Invoke-Command @invokeParams
    $duration = [int]((Get-Date) - $started).TotalSeconds
    $results.Add([PSCustomObject]@{
      ip = $ip
      ok = $true
      seconds = $duration
      message = ($response.message -join " | ")
    }) | Out-Null
    Write-Host "[$ip] success ($duration s)"
  }
  catch {
    $duration = [int]((Get-Date) - $started).TotalSeconds
    $results.Add([PSCustomObject]@{
      ip = $ip
      ok = $false
      seconds = $duration
      message = $_.Exception.Message
    }) | Out-Null
    Write-Host "[$ip] failed ($duration s): $($_.Exception.Message)"
  }
}

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$outDir = Join-Path $PSScriptRoot "logs"
New-Item -ItemType Directory -Path $outDir -Force | Out-Null
$outPath = Join-Path $outDir "install-agent-by-ip-$timestamp.csv"
$results | Export-Csv -Path $outPath -NoTypeInformation

$okCount = @($results | Where-Object { $_.ok }).Count
$failCount = @($results | Where-Object { -not $_.ok }).Count
Write-Host ""
Write-Host "Completed. Success: $okCount | Failed: $failCount"
Write-Host "Report: $outPath"
