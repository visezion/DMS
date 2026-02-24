param(
  [Parameter(Mandatory = $false)][string]$InstallScriptUrl,
  [Parameter(Mandatory = $false)][string]$TargetIp,
  [Parameter(Mandatory = $false)][string]$TargetListPath,
  [Parameter(Mandatory = $false)][string]$IpRangeCidr,
  [Parameter(Mandatory = $false)][string]$Username,
  [Parameter(Mandatory = $false)][string]$Password,
  [Parameter(Mandatory = $false)][switch]$SkipPortChecks,
  [Parameter(Mandatory = $false)][switch]$WhatIf
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Test-SignedUrl {
  param([string]$Url)

  if ([string]::IsNullOrWhiteSpace($Url)) {
    throw "InstallScriptUrl is required."
  }

  $hasExpires = $Url -match '([?&])expires='
  $hasSignature = $Url -match '([?&])signature='
  if (-not $hasExpires -or -not $hasSignature) {
    throw "InstallScriptUrl must be a signed URL containing expires and signature query params."
  }
}

function Convert-IpToUInt32 {
  param([string]$Ip)

  $bytes = ([System.Net.IPAddress]::Parse($Ip)).GetAddressBytes()
  [array]::Reverse($bytes)
  return [BitConverter]::ToUInt32($bytes, 0)
}

function Convert-UInt32ToIp {
  param([uint32]$Value)

  $bytes = [BitConverter]::GetBytes($Value)
  [array]::Reverse($bytes)
  return ([System.Net.IPAddress]::new($bytes)).ToString()
}

function Expand-Cidr {
  param([string]$Cidr)

  if ([string]::IsNullOrWhiteSpace($Cidr)) {
    return @()
  }

  $parts = $Cidr.Split('/')
  if ($parts.Count -ne 2) {
    throw "Invalid CIDR format. Use like 172.16.155.0/24"
  }

  $baseIp = $parts[0].Trim()
  $prefix = [int]$parts[1]
  if ($prefix -lt 0 -or $prefix -gt 32) {
    throw "Invalid CIDR prefix: $prefix"
  }

  $base = Convert-IpToUInt32 -Ip $baseIp
  $hostBits = 32 - $prefix
  $size = [math]::Pow(2, $hostBits)
  $mask = if ($prefix -eq 0) { [uint32]0 } else { [uint32]::MaxValue -shl $hostBits }
  $network = $base -band $mask

  $targets = New-Object System.Collections.Generic.List[string]
  for ($i = 0; $i -lt $size; $i++) {
    $ip = Convert-UInt32ToIp -Value ([uint32]($network + [uint32]$i))
    $targets.Add($ip) | Out-Null
  }

  if ($prefix -le 30 -and $targets.Count -ge 2) {
    # Skip network and broadcast for typical LAN CIDRs.
    return @($targets | Select-Object -Skip 1 | Select-Object -SkipLast 1)
  }

  return @($targets)
}

function Read-Targets {
  param(
    [string]$SingleIp,
    [string]$ListPath,
    [string]$Cidr
  )

  $targets = New-Object System.Collections.Generic.List[string]
  if (-not [string]::IsNullOrWhiteSpace($SingleIp)) {
    $targets.Add($SingleIp.Trim()) | Out-Null
  }

  if (-not [string]::IsNullOrWhiteSpace($ListPath)) {
    if (-not (Test-Path -Path $ListPath -PathType Leaf)) {
      throw "Target list file not found: $ListPath"
    }

    foreach ($line in (Get-Content -Path $ListPath -ErrorAction Stop)) {
      $value = $line.Trim()
      if ([string]::IsNullOrWhiteSpace($value)) { continue }
      if ($value.StartsWith("#")) { continue }
      $targets.Add($value) | Out-Null
    }
  }

  foreach ($ip in (Expand-Cidr -Cidr $Cidr)) {
    $targets.Add($ip) | Out-Null
  }

  $distinct = @($targets | Sort-Object -Unique)
  if ($distinct.Count -eq 0) {
    throw "No targets provided. Use -TargetIp, -TargetListPath, or -IpRangeCidr."
  }

  return $distinct
}

function Test-TargetPorts {
  param([string]$Ip)

  $p445 = Test-NetConnection -ComputerName $Ip -Port 445 -WarningAction SilentlyContinue
  $p135 = Test-NetConnection -ComputerName $Ip -Port 135 -WarningAction SilentlyContinue
  return @{
    smb445 = [bool]$p445.TcpTestSucceeded
    rpc135 = [bool]$p135.TcpTestSucceeded
  }
}

function Invoke-NetUse {
  param(
    [string]$Ip,
    [string]$User,
    [string]$Pass
  )

  if ([string]::IsNullOrWhiteSpace($User) -or [string]::IsNullOrWhiteSpace($Pass)) {
    throw "Username and Password are required for SMB/RPC deploy mode."
  }

  $share = "\\$Ip\ADMIN$"
  cmd /c "net use $share /delete /y" | Out-Null
  cmd /c "net use $share /user:`"$User`" `"$Pass`""
  if ($LASTEXITCODE -ne 0) {
    throw "Failed to authenticate ADMIN$ share using provided credentials."
  }
}

function Remove-NetUse {
  param([string]$Ip)
  $share = "\\$Ip\ADMIN$"
  cmd /c "net use $share /delete /y" | Out-Null
}

function Invoke-RemoteInstallOverSc {
  param(
    [string]$Ip,
    [string]$Url
  )

  $remoteDir = "\\$Ip\ADMIN$\Temp\DMS"
  New-Item -ItemType Directory -Path $remoteDir -Force | Out-Null

  $bootstrapPath = Join-Path $remoteDir "install-dms-agent.ps1"
  $bootstrapContent = @"
\$ErrorActionPreference = "Stop"
iwr -useb '$Url' | iex
"@
  Set-Content -Path $bootstrapPath -Value $bootstrapContent -Encoding ASCII

  $serviceName = "DMSBootstrap_" + ([guid]::NewGuid().ToString("N").Substring(0, 8))
  $binPath = "powershell -NoProfile -ExecutionPolicy Bypass -File C:\Windows\Temp\DMS\install-dms-agent.ps1"

  cmd /c "sc \\$Ip create $serviceName binPath= `"$binPath`" start= demand" | Out-Null
  if ($LASTEXITCODE -ne 0) {
    throw "Failed to create remote service on $Ip."
  }

  cmd /c "sc \\$Ip start $serviceName" | Out-Null
  cmd /c "sc \\$Ip delete $serviceName" | Out-Null
}

if (-not $WhatIf) {
  Test-SignedUrl -Url $InstallScriptUrl
}
$targets = @(Read-Targets -SingleIp $TargetIp -ListPath $TargetListPath -Cidr $IpRangeCidr)
$results = New-Object System.Collections.Generic.List[object]

Write-Host "Targets: $($targets.Count)"
Write-Host "WhatIf: $($WhatIf.IsPresent)"
Write-Host "Starting SMB/RPC remote install..."

foreach ($ip in $targets) {
  $started = Get-Date
  Write-Host "[$ip] processing..."
  try {
    if (-not $SkipPortChecks) {
      $ports = Test-TargetPorts -Ip $ip
      if (-not $ports.smb445 -or -not $ports.rpc135) {
        throw "Required ports not reachable (445=$($ports.smb445), 135=$($ports.rpc135))."
      }
    }

    if (-not $WhatIf) {
      Invoke-NetUse -Ip $ip -User $Username -Pass $Password
      try {
        Invoke-RemoteInstallOverSc -Ip $ip -Url $InstallScriptUrl
      }
      finally {
        Remove-NetUse -Ip $ip
      }
    }

    $duration = [int]((Get-Date) - $started).TotalSeconds
    $results.Add([pscustomobject]@{
      ip = $ip
      ok = $true
      seconds = $duration
      message = if ($WhatIf) { "WhatIf mode: validated target and skipped remote execution." } else { "Remote install command started via SMB/RPC." }
    }) | Out-Null
    Write-Host "[$ip] success ($duration s)"
  }
  catch {
    $duration = [int]((Get-Date) - $started).TotalSeconds
    $results.Add([pscustomobject]@{
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
$outPath = Join-Path $outDir "install-agent-by-smb-rpc-$timestamp.csv"
$results | Export-Csv -Path $outPath -NoTypeInformation

$okCount = @($results | Where-Object { $_.ok }).Count
$failCount = @($results | Where-Object { -not $_.ok }).Count
Write-Host ""
Write-Host "Completed. Success: $okCount | Failed: $failCount"
Write-Host "Report: $outPath"
