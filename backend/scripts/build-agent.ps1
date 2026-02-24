param(
  [Parameter(Mandatory = $true)][string]$AgentRoot,
  [Parameter(Mandatory = $true)][string]$OutputRoot,
  [Parameter(Mandatory = $true)][string]$Version,
  [Parameter(Mandatory = $false)][string]$Runtime = "win-x64",
  [Parameter(Mandatory = $false)][string]$SelfContained = "true",
  [Parameter(Mandatory = $false)][int]$RetentionDays = 7,
  [Parameter(Mandatory = $false)][int]$RetentionCount = 10
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

function Remove-OldArtifacts {
  param(
    [Parameter(Mandatory = $true)][string]$RootPath,
    [Parameter(Mandatory = $true)][string]$Pattern,
    [Parameter(Mandatory = $true)][int]$Days,
    [Parameter(Mandatory = $true)][int]$KeepCount
  )

  if (!(Test-Path $RootPath)) { return }

  $cutoff = (Get-Date).AddDays(-1 * [Math]::Abs($Days))
  $items = Get-ChildItem -Path $RootPath -Filter $Pattern -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTime -Descending

  if ($null -eq $items) { return }

  $index = 0
  foreach ($item in $items) {
    $index++
    $shouldKeep = $index -le [Math]::Max(1, $KeepCount)
    $isOld = $item.LastWriteTime -lt $cutoff

    if (-not $shouldKeep -or $isOld) {
      try {
        if ($item.PSIsContainer) {
          Remove-Item $item.FullName -Recurse -Force -ErrorAction Stop
        }
        else {
          Remove-Item $item.FullName -Force -ErrorAction Stop
        }
      }
      catch {
        # Ignore locked artifacts; they will be retried on next run.
      }
    }
  }
}

if (!(Test-Path $AgentRoot)) {
  throw "AgentRoot does not exist: $AgentRoot"
}

if (!(Test-Path $OutputRoot)) {
  New-Item -ItemType Directory -Path $OutputRoot -Force | Out-Null
}

$safeVersion = ($Version -replace '[^0-9A-Za-z\.\-_]', '-').Trim('-')
if ([string]::IsNullOrWhiteSpace($safeVersion)) { $safeVersion = "build" }
$safeRuntime = ($Runtime -replace '[^0-9A-Za-z\.\-_]', '-').Trim('-')
if ([string]::IsNullOrWhiteSpace($safeRuntime)) { $safeRuntime = "win-x64" }
$buildId = ([Guid]::NewGuid().ToString("N").Substring(0,8))

$publishDir = Join-Path $AgentRoot "dist\agent-build-$safeVersion-$safeRuntime-$buildId"
$bundleDir = Join-Path $AgentRoot "dist\bundle-$safeVersion-$safeRuntime-$buildId"
$installerDir = Join-Path $AgentRoot "installer"
$dotnetHome = Join-Path $AgentRoot ".dotnet-home"
$nugetPackages = Join-Path $dotnetHome ".nuget\packages"
$nugetHttpCache = Join-Path $dotnetHome ".nuget\http-cache"
$nugetPluginCache = Join-Path $dotnetHome ".nuget\plugins-cache"
$localAppData = Join-Path $dotnetHome "AppData\Local"
$appData = Join-Path $dotnetHome "AppData\Roaming"
$tempPath = Join-Path $dotnetHome "Temp"
$programData = "C:\ProgramData"
$programFiles = ${env:ProgramFiles}
$programFilesX86 = ${env:ProgramFiles(x86)}

if ([string]::IsNullOrWhiteSpace($programFiles)) { $programFiles = "C:\Program Files" }
if ([string]::IsNullOrWhiteSpace($programFilesX86)) { $programFilesX86 = "C:\Program Files (x86)" }

New-Item -ItemType Directory -Path $dotnetHome -Force | Out-Null
New-Item -ItemType Directory -Path $nugetPackages -Force | Out-Null
New-Item -ItemType Directory -Path $nugetHttpCache -Force | Out-Null
New-Item -ItemType Directory -Path $nugetPluginCache -Force | Out-Null
New-Item -ItemType Directory -Path $localAppData -Force | Out-Null
New-Item -ItemType Directory -Path $appData -Force | Out-Null
New-Item -ItemType Directory -Path $tempPath -Force | Out-Null
New-Item -ItemType Directory -Path $programData -Force | Out-Null

Remove-OldArtifacts -RootPath (Join-Path $AgentRoot "dist") -Pattern "agent-build-*" -Days $RetentionDays -KeepCount $RetentionCount
Remove-OldArtifacts -RootPath (Join-Path $AgentRoot "dist") -Pattern "bundle-*" -Days $RetentionDays -KeepCount $RetentionCount
Remove-OldArtifacts -RootPath $OutputRoot -Pattern "dms-agent-*.zip" -Days $RetentionDays -KeepCount $RetentionCount

$env:DOTNET_CLI_TELEMETRY_OPTOUT = "1"
$env:DOTNET_SKIP_FIRST_TIME_EXPERIENCE = "1"
$env:DOTNET_NOLOGO = "1"
$env:DOTNET_CLI_HOME = $dotnetHome
$env:MSBuildEnableWorkloadResolver = "false"
$env:NUGET_XMLDOC_MODE = "skip"
$env:HOME = $dotnetHome
$env:USERPROFILE = $dotnetHome
$env:LOCALAPPDATA = $localAppData
$env:APPDATA = $appData
$env:ALLUSERSPROFILE = $programData
$env:ProgramData = $programData
$env:ProgramFiles = $programFiles
Set-Item -Path 'Env:ProgramFiles(x86)' -Value $programFilesX86
$env:TEMP = $tempPath
$env:TMP = $tempPath
$env:NUGET_PACKAGES = $nugetPackages
$env:NUGET_HTTP_CACHE_PATH = $nugetHttpCache
$env:NUGET_PLUGINS_CACHE_PATH = $nugetPluginCache
$env:NUGET_COMMON_APPLICATION_DATA = $programData

$sc = if ($SelfContained -eq "true") { "true" } else { "false" }
$nugetConfigPath = Join-Path $dotnetHome "NuGet.Config"
$nugetConfigXml = @"
<?xml version="1.0" encoding="utf-8"?>
<configuration>
  <packageSources>
    <clear />
    <add key="nuget.org" value="https://api.nuget.org/v3/index.json" />
  </packageSources>
  <config>
    <add key="globalPackagesFolder" value="$nugetPackages" />
  </config>
</configuration>
"@
if (Test-Path $nugetConfigPath) { Remove-Item $nugetConfigPath -Force }
[System.IO.File]::WriteAllText($nugetConfigPath, $nugetConfigXml, [System.Text.UTF8Encoding]::new($false))

Push-Location $AgentRoot
try {
  $restoreTarget = ".\src\Dms.Agent.Service\Dms.Agent.Service.csproj"
  & dotnet restore $restoreTarget -r $safeRuntime --nologo --configfile $nugetConfigPath --packages $nugetPackages
  if ($LASTEXITCODE -ne 0) { throw "dotnet restore failed with exit code $LASTEXITCODE" }

  $informationalVersion = "$safeVersion+$buildId"
  & dotnet publish $restoreTarget -c Release -r $safeRuntime -p:PublishSingleFile=true -p:SelfContained=$sc -p:InformationalVersion=$informationalVersion -p:Version=$safeVersion --no-restore -o $publishDir --nologo
  if ($LASTEXITCODE -ne 0) { throw "dotnet publish failed with exit code $LASTEXITCODE" }

  if (!(Test-Path $publishDir)) {
    throw "Publish output folder missing: $publishDir"
  }

  New-Item -ItemType Directory -Path $bundleDir -Force | Out-Null
  New-Item -ItemType Directory -Path (Join-Path $bundleDir "agent") -Force | Out-Null
  Copy-Item "$publishDir\*" -Destination (Join-Path $bundleDir "agent") -Recurse -Force

  if (Test-Path $installerDir) {
    Copy-Item $installerDir -Destination (Join-Path $bundleDir "installer") -Recurse -Force
  }

  $readme = @"
DMS Agent Bundle
Version: $safeVersion
Runtime: $safeRuntime
BuiltAt: $(Get-Date -Format o)
"@
  Set-Content -Path (Join-Path $bundleDir "README.txt") -Value $readme

  $zipName = "dms-agent-$safeVersion-$safeRuntime-$buildId.zip"
  $zipPath = Join-Path $OutputRoot $zipName
  $attempt = 0
  while ($true) {
    try {
      if (Test-Path $zipPath) { Remove-Item $zipPath -Force -ErrorAction SilentlyContinue }
      Compress-Archive -Path "$bundleDir\*" -DestinationPath $zipPath -Force
      break
    }
    catch {
      $attempt++
      if ($attempt -ge 5) { throw }
      Start-Sleep -Milliseconds (500 * $attempt)
    }
  }

  Write-Output "Build completed. Artifact: $zipPath"
}
finally {
  Pop-Location
}
