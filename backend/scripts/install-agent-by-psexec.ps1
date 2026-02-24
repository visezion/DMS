param(
  [Parameter(Mandatory = $false)][string]$InstallScriptUrl,
  [Parameter(Mandatory = $false)][string]$TargetIp,
  [Parameter(Mandatory = $false)][string]$TargetListPath,
  [Parameter(Mandatory = $false)][string]$IpRangeCidr,
  [Parameter(Mandatory = $false)][string]$Username,
  [Parameter(Mandatory = $false)][string]$Password,
  [Parameter(Mandatory = $false)][switch]$AutoBootstrap,
  [Parameter(Mandatory = $false)][switch]$BootstrapOnly,
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
  if ($Url -notmatch '([?&])expires=' -or $Url -notmatch '([?&])signature=') {
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
    $targets.Add((Convert-UInt32ToIp -Value ([uint32]($network + [uint32]$i))) ) | Out-Null
  }

  if ($prefix -le 30 -and $targets.Count -ge 2) {
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
      if ([string]::IsNullOrWhiteSpace($value) -or $value.StartsWith("#")) { continue }
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
  return [bool]$p445.TcpTestSucceeded
}

function Invoke-PsExecCommand {
  param(
    [string]$Ip,
    [string]$User,
    [string]$Pass,
    [string[]]$RemoteCommandArgs
  )

  if ([string]::IsNullOrWhiteSpace($User) -or [string]::IsNullOrWhiteSpace($Pass)) {
    throw "Username and Password are required for PsExec deploy mode."
  }

  $psexecCmd = Get-Command psexec.exe -ErrorAction SilentlyContinue
  $psexec = if ($psexecCmd) { $psexecCmd.Source } else { $null }
  if ([string]::IsNullOrWhiteSpace($psexec)) {
    throw "psexec.exe not found in PATH."
  }

  $args = @(
    "\\$Ip",
    "-accepteula",
    "-nobanner",
    "-u", $User,
    "-p", $Pass,
    "-h",
    "-s",
    "-n", "10",
    "cmd", "/c"
  )
  $args += $RemoteCommandArgs

  $out = & $psexec @args 2>&1
  if ($LASTEXITCODE -ne 0) {
    $joined = ($out | ForEach-Object { $_.ToString().Trim() } | Where-Object { $_ -ne '' }) -join ' | '
    if ([string]::IsNullOrWhiteSpace($joined)) {
      $joined = "PsExec failed with code $LASTEXITCODE"
    }
    throw $joined
  }
}

function Invoke-PsExecBootstrap {
  param(
    [string]$Ip,
    [string]$User,
    [string]$Pass
  )

  $bootstrap = @'
$ErrorActionPreference = "Stop"
New-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System" -Name "LocalAccountTokenFilterPolicy" -PropertyType DWord -Value 1 -Force | Out-Null
New-ItemProperty -Path "HKLM:\SYSTEM\CurrentControlSet\Services\LanmanServer\Parameters" -Name "AutoShareWks" -PropertyType DWord -Value 1 -Force | Out-Null
Enable-PSRemoting -Force -SkipNetworkProfileCheck | Out-Null
netsh advfirewall firewall set rule group="File and Printer Sharing" new enable=Yes | Out-Null
netsh advfirewall firewall set rule group="Windows Management Instrumentation (WMI)" new enable=Yes | Out-Null
netsh advfirewall firewall set rule group="Remote Service Management" new enable=Yes | Out-Null
netsh advfirewall firewall set rule group="Remote Scheduled Tasks Management" new enable=Yes | Out-Null
'@
  $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($bootstrap))
  Invoke-PsExecCommand -Ip $Ip -User $User -Pass $Pass -RemoteCommandArgs @(
    "powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-EncodedCommand", $encoded
  )
}

function Invoke-PsExecInstall {
  param(
    [string]$Ip,
    [string]$User,
    [string]$Pass,
    [string]$Url
  )
  $install = "iwr -useb '$Url' | iex"
  $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($install))
  Invoke-PsExecCommand -Ip $Ip -User $User -Pass $Pass -RemoteCommandArgs @(
    "powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-EncodedCommand", $encoded
  )
}

if (-not $WhatIf -and -not $BootstrapOnly) {
  Test-SignedUrl -Url $InstallScriptUrl
}
$targets = @(Read-Targets -SingleIp $TargetIp -ListPath $TargetListPath -Cidr $IpRangeCidr)
$results = New-Object System.Collections.Generic.List[object]

Write-Host "Targets: $($targets.Count)"
Write-Host "WhatIf: $($WhatIf.IsPresent)"
Write-Host "Starting PsExec remote install..."

foreach ($ip in $targets) {
  $started = Get-Date
  Write-Host "[$ip] processing..."
  try {
    if (-not $SkipPortChecks) {
      $p445 = Test-TargetPorts -Ip $ip
      if (-not $p445) {
        throw "Required port not reachable (445=$p445)."
      }
    }

    if (-not $WhatIf) {
      if ($AutoBootstrap -or $BootstrapOnly) {
        Invoke-PsExecBootstrap -Ip $ip -User $Username -Pass $Password
      }

      if (-not $BootstrapOnly) {
        Invoke-PsExecInstall -Ip $ip -User $Username -Pass $Password -Url $InstallScriptUrl
      }
    }

    $duration = [int]((Get-Date) - $started).TotalSeconds
    $results.Add([pscustomobject]@{
      ip = $ip
      ok = $true
      seconds = $duration
      message = if ($WhatIf) {
        "WhatIf mode: validated target and skipped remote execution."
      } elseif ($BootstrapOnly) {
        "Bootstrap applied via PsExec."
      } elseif ($AutoBootstrap) {
        "Bootstrap + remote install command started via PsExec."
      } else {
        "Remote install command started via PsExec."
      }
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
$outPath = Join-Path $outDir "install-agent-by-psexec-$timestamp.csv"
$results | Export-Csv -Path $outPath -NoTypeInformation

$okCount = @($results | Where-Object { $_.ok }).Count
$failCount = @($results | Where-Object { -not $_.ok }).Count
Write-Host ""
Write-Host "Completed. Success: $okCount | Failed: $failCount"
Write-Host "Report: $outPath"
