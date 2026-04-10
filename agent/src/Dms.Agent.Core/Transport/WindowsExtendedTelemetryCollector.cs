using System.Diagnostics;
using System.Text;
using System.Text.Json;

namespace Dms.Agent.Core.Transport;

internal static class WindowsExtendedTelemetryCollector
{
    private static readonly object Sync = new();
    private static DateTimeOffset _lastCollectedAt = DateTimeOffset.MinValue;
    private static Dictionary<string, object?>? _cached;

    public static async Task<Dictionary<string, object?>> CollectAsync(CancellationToken cancellationToken)
    {
        if (!OperatingSystem.IsWindows())
        {
            return new Dictionary<string, object?>
            {
                ["supported"] = false,
                ["collector"] = "windows_extended_telemetry",
                ["reason"] = "windows_only",
                ["collected_at"] = DateTimeOffset.UtcNow.ToString("O"),
            };
        }

        TimeSpan ttl = ResolveSeconds("DMS_EXTENDED_TELEMETRY_CACHE_SECONDS", 300, 30, 1800);
        lock (Sync)
        {
            if (_cached is not null && (DateTimeOffset.UtcNow - _lastCollectedAt) < ttl)
            {
                return _cached;
            }
        }

        Dictionary<string, object?> data = await CollectInternalAsync(cancellationToken);
        data["collector"] = "windows_extended_telemetry";
        data["collector_version"] = "2026-03-23.2";
        data["collected_at"] = DateTimeOffset.UtcNow.ToString("O");

        lock (Sync)
        {
            _cached = data;
            _lastCollectedAt = DateTimeOffset.UtcNow;
        }

        return data;
    }

    private static async Task<Dictionary<string, object?>> CollectInternalAsync(CancellationToken cancellationToken)
    {
        string scriptPath = EnsureScriptFile();
        var psi = new ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = $"-NoProfile -NonInteractive -ExecutionPolicy Bypass -File \"{scriptPath}\"",
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
        };

        using var process = Process.Start(psi);
        if (process is null)
        {
            return Error("failed_to_start_powershell", null, null);
        }

        Task<string> stdoutTask = process.StandardOutput.ReadToEndAsync();
        Task<string> stderrTask = process.StandardError.ReadToEndAsync();

        TimeSpan timeout = ResolveSeconds("DMS_EXTENDED_TELEMETRY_TIMEOUT_SECONDS", 180, 30, 600);
        using var timeoutCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeoutCts.CancelAfter(timeout);

        try
        {
            await process.WaitForExitAsync(timeoutCts.Token);
        }
        catch (OperationCanceledException)
        {
            TryKill(process);
            return Error("telemetry_timeout_or_cancelled", await stdoutTask, await stderrTask);
        }
        catch (Exception ex)
        {
            TryKill(process);
            return Error(ex.Message, await stdoutTask, await stderrTask);
        }

        string stdout = (await stdoutTask).Trim();
        string stderr = (await stderrTask).Trim();
        if (process.ExitCode != 0)
        {
            return Error($"powershell_exit_{process.ExitCode}", stdout, stderr);
        }

        string? payload = ExtractPayload(stdout);
        if (string.IsNullOrWhiteSpace(payload))
        {
            return Error("json_payload_missing", stdout, stderr);
        }

        try
        {
            using JsonDocument doc = JsonDocument.Parse(payload);
            if (doc.RootElement.ValueKind != JsonValueKind.Object)
            {
                return Error("json_payload_not_object", stdout, stderr);
            }

            return ConvertObject(doc.RootElement);
        }
        catch (Exception ex)
        {
            return Error($"json_parse_error:{ex.Message}", stdout, stderr);
        }
    }

    private static string EnsureScriptFile()
    {
        string programData = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
        string telemetryDir = Path.Combine(programData, "DMS", "Telemetry");
        Directory.CreateDirectory(telemetryDir);

        string scriptPath = Path.Combine(telemetryDir, "windows-extended-telemetry.ps1");
        try
        {
            if (!File.Exists(scriptPath) || !string.Equals(File.ReadAllText(scriptPath), Script, StringComparison.Ordinal))
            {
                File.WriteAllText(scriptPath, Script, Encoding.UTF8);
            }
        }
        catch
        {
            // Let Process.Start surface the underlying file issue if this cannot be written.
        }

        return scriptPath;
    }

    private static TimeSpan ResolveSeconds(string envName, int fallbackSeconds, int minSeconds, int maxSeconds)
    {
        string? raw = Environment.GetEnvironmentVariable(envName);
        if (int.TryParse(raw, out int seconds))
        {
            return TimeSpan.FromSeconds(Math.Clamp(seconds, minSeconds, maxSeconds));
        }

        return TimeSpan.FromSeconds(fallbackSeconds);
    }

    private static void TryKill(Process process)
    {
        try
        {
            if (!process.HasExited)
            {
                process.Kill(entireProcessTree: true);
            }
        }
        catch
        {
            // Ignore kill failures.
        }
    }

    private static string? ExtractPayload(string stdout)
    {
        const string prefix = "DMS_JSON::";
        foreach (string line in stdout.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries).Reverse())
        {
            if (line.StartsWith(prefix, StringComparison.Ordinal))
            {
                return line[prefix.Length..].Trim();
            }
        }

        return null;
    }

    private static Dictionary<string, object?> Error(string reason, string? stdout, string? stderr)
    {
        return new Dictionary<string, object?>
        {
            ["supported"] = true,
            ["collection_error"] = reason,
            ["stdout_tail"] = Tail(stdout),
            ["stderr_tail"] = Tail(stderr),
            ["collected_at"] = DateTimeOffset.UtcNow.ToString("O"),
        };
    }

    private static string? Tail(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return null;
        }

        string text = value.Trim();
        const int max = 800;
        return text.Length <= max ? text : text[^max..];
    }

    private static Dictionary<string, object?> ConvertObject(JsonElement element)
    {
        var result = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase);
        foreach (JsonProperty property in element.EnumerateObject())
        {
            result[property.Name] = ConvertValue(property.Value);
        }

        return result;
    }

    private static object? ConvertValue(JsonElement element)
    {
        return element.ValueKind switch
        {
            JsonValueKind.Object => ConvertObject(element),
            JsonValueKind.Array => element.EnumerateArray().Select(ConvertValue).ToList(),
            JsonValueKind.String => element.GetString(),
            JsonValueKind.Number => element.TryGetInt64(out long l) ? l : element.GetDouble(),
            JsonValueKind.True => true,
            JsonValueKind.False => false,
            _ => null,
        };
    }

    private const string Script = """
$ErrorActionPreference='SilentlyContinue';$ProgressPreference='SilentlyContinue'
$d1=(Get-Date).AddDays(-1);$d7=(Get-Date).AddDays(-7);$d30=(Get-Date).AddDays(-30)
function S([scriptblock]$b,$d=$null){try{& $b}catch{$d}}
function EC($l,$ids,$s){S{ $f=@{LogName=$l;StartTime=$s}; if($ids){$f.Id=$ids}; (Get-WinEvent -FilterHashtable $f -MaxEvents 1200|Measure-Object).Count } 0}
function CT($v,$n=220){ if([string]::IsNullOrWhiteSpace($v)){ return $null }; $c=(($v -replace "`r`n",' ') -replace '\s+',' ').Trim(); if($c.Length -le $n){ return $c }; return $c.Substring(0,$n) }
function RE($l,$ids,$s,$m=24){S{ $f=@{LogName=$l;StartTime=$s}; if($ids){$f.Id=$ids}; Get-WinEvent -FilterHashtable $f -MaxEvents $m | % { [ordered]@{id=$_.Id;provider=$_.ProviderName;time_utc=([DateTimeOffset]$_.TimeCreated).ToUniversalTime().ToString('o');message=(CT $_.Message 220)} } } @() }
function ED($e,$n){S{ $x=[xml]$e.ToXml(); (($x.Event.EventData.Data|?{$_.Name -eq $n}|select -First 1).'#text') } $null}
$os=S{Get-CimInstance Win32_OperatingSystem};$cs=S{Get-CimInstance Win32_ComputerSystem};$bios=S{Get-CimInstance Win32_BIOS};$cv=S{Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'}
$ad=S{Get-NetAdapter|select -First 64 Name,InterfaceDescription,Status,MacAddress,LinkSpeed,ifIndex} @()
$ips=S{Get-NetIPAddress -AddressFamily IPv4,IPv6|?{$_.IPAddress -ne '127.0.0.1' -and $_.IPAddress -ne '::1' -and $_.IPAddress -notlike '169.254*'}|select -First 128 IPAddress,InterfaceAlias,AddressFamily,PrefixLength} @()
$ds=S{(& dsregcmd /status)-join "`n"} ''
$aad=($ds -match 'AzureAdJoined\s*:\s*YES')
$identity=[ordered]@{
 device_id=$env:DMS_DEVICE_ID;hostname=$env:COMPUTERNAME;serial_number=$bios.SerialNumber;manufacturer=$cs.Manufacturer;model=$cs.Model;
 windows_edition=$cv.ProductName;windows_build_number=$cv.CurrentBuildNumber;windows_display_version=$cv.DisplayVersion;
 bios_uefi_version=$bios.SMBIOSBIOSVersion;mac_addresses=@($ad|%{$_.MacAddress}|?{$_}|select -Unique -First 20);
 ip_addresses=@($ips|%{$_.IPAddress}|select -Unique -First 40);logged_in_user=$cs.UserName;department_or_owner=($(if($env:DMS_DEVICE_OWNER){$env:DMS_DEVICE_OWNER}else{$cv.RegisteredOwner}));
 domain_joined=[bool]$cs.PartOfDomain;domain_name=$(if([bool]$cs.PartOfDomain){$cs.Domain}else{$null});azure_ad_joined=[bool]$aad;physical_location=$env:DMS_DEVICE_LOCATION
}
$cpu=S{[math]::Round((Get-Counter '\Processor(_Total)\% Processor Time').CounterSamples[0].CookedValue,2)} $null
$io=S{[math]::Round((Get-Counter '\PhysicalDisk(_Total)\Disk Bytes/sec').CounterSamples[0].CookedValue,2)} $null
$tMem=$(if($os){[int64]$os.TotalVisibleMemorySize*1024}else{$null});$fMem=$(if($os){[int64]$os.FreePhysicalMemory*1024}else{$null});$uMem=$(if($tMem -and $fMem -ne $null){[math]::Max(0,$tMem-$fMem)}else{$null})
$dsk=S{Get-CimInstance Win32_LogicalDisk -Filter "DriveType=3"|select DeviceID,VolumeName,FileSystem,Size,FreeSpace} @()
$svc=S{Get-Service|select -First 700 Name,Status,StartType} @()
$sysHealth=[ordered]@{
 cpu_usage_percent=$cpu;memory_usage_percent=$(if($tMem -and $tMem -gt 0 -and $uMem -ne $null){[math]::Round(($uMem/$tMem)*100,2)}else{$null});
 memory_total_bytes=$tMem;memory_used_bytes=$uMem;disk_space_per_drive=@($dsk|%{[ordered]@{drive=$_.DeviceID;total_bytes=$_.Size;free_bytes=$_.FreeSpace;used_percent=$(if($_.Size -gt 0){[math]::Round((($_.Size-$_.FreeSpace)/$_.Size)*100,2)}else{$null})}});
 disk_io_bytes_per_second=$io;disk_health=S{Get-PhysicalDisk|select -First 24 FriendlyName,HealthStatus,OperationalStatus,MediaType,Size} @();
 boot_time_utc=S{([DateTimeOffset]$os.LastBootUpTime).ToUniversalTime().ToString('o')} $null;uptime_seconds=$(if($os){[math]::Round(((Get-Date).ToUniversalTime()-([DateTime]$os.LastBootUpTime).ToUniversalTime()).TotalSeconds,0)}else{$null});
 shutdown_history=RE 'System' @(1074,6006,6008,41) $d7 40;blue_screen_history=RE 'System' @(1001,41) $d30 30;temperature_celsius=S{Get-CimInstance -Namespace root/wmi -Class MSAcpi_ThermalZoneTemperature|select -First 10 @{N='name';E={$_.InstanceName}},@{N='celsius';E={[math]::Round(($_.CurrentTemperature-2732)/10,1)}}} @();
 battery_health=S{Get-CimInstance Win32_Battery|select -First 10 Name,BatteryStatus,EstimatedChargeRemaining,EstimatedRunTime} @();
 gpu_usage=S{(Get-Counter '\GPU Engine(*)\Utilization Percentage').CounterSamples|?{$_.InstanceName -notlike '*_Total*' -and $_.CookedValue -gt 0}|sort CookedValue -desc|select -First 20 @{N='engine';E={$_.InstanceName}},@{N='utilization_percent';E={[math]::Round($_.CookedValue,2)}}} @();
 network_adapter_health=@($ad|%{[ordered]@{name=$_.Name;status=$_.Status;link_speed=$_.LinkSpeed;mac=$_.MacAddress}});running_services_status=[ordered]@{total=@($svc).Count;running=@($svc|?{$_.Status -eq 'Running'}).Count;stopped=@($svc|?{$_.Status -eq 'Stopped'}).Count;sample=@($svc|select -First 200)};
 frequent_crashes_24h=EC 'Application' @(1000,1002,1005) $d1;service_failures_24h=EC 'System' @(7000,7001,7009,7011,7023,7031,7034) $d1
}
$logs=[ordered]@{system=EC 'System' @() $d1;application=EC 'Application' @() $d1;security=EC 'Security' @() $d1;setup=EC 'Setup' @() $d1;microsoft_defender=EC 'Microsoft-Windows-Windows Defender/Operational' @() $d1;powershell_operational=EC 'Microsoft-Windows-PowerShell/Operational' @() $d1;task_scheduler_operational=EC 'Microsoft-Windows-TaskScheduler/Operational' @() $d1;windows_update_operational=EC 'Microsoft-Windows-WindowsUpdateClient/Operational' @() $d1;rdp_operational=EC 'Microsoft-Windows-TerminalServices-LocalSessionManager/Operational' @() $d1;device_setup=EC 'Microsoft-Windows-DeviceSetupManager/Admin' @() $d1;bitlocker=EC 'Microsoft-Windows-BitLocker/BitLocker Management' @() $d1}
$important=[ordered]@{failed_logins_24h=EC 'Security' @(4625) $d1;successful_logins_24h=EC 'Security' @(4624) $d1;app_crashes_24h=EC 'Application' @(1000,1002,1005) $d1;service_failures_24h=EC 'System' @(7000,7001,7009,7011,7023,7031,7034) $d1;driver_errors_24h=EC 'System' @(219) $d1;unexpected_shutdowns_24h=EC 'System' @(6008,41) $d1;restart_reasons_24h=EC 'System' @(1074) $d1;account_lockouts_24h=EC 'Security' @(4740) $d1;privilege_escalation_24h=EC 'Security' @(4672) $d1;policy_changes_24h=EC 'Security' @(4719,4739) $d1}
$eventLogs=[ordered]@{logs_24h=$logs;important_event_counts_24h=$important;recent_samples=[ordered]@{security=RE 'Security' @(4624,4625,4672,4740,4688,4663) $d1 18;system=RE 'System' @(41,1074,6008,7000,7034,219) $d1 18;application=RE 'Application' @(1000,1002,11707,11724) $d1 18;windows_update=RE 'Microsoft-Windows-WindowsUpdateClient/Operational' @(19,20,31,34) $d7 18;defender=RE 'Microsoft-Windows-Windows Defender/Operational' @(1116,1117,1121,5007) $d7 18}}
$proc=S{Get-CimInstance Win32_Process|select ProcessId,ParentProcessId,Name,ExecutablePath,CommandLine,CreationDate,WorkingSetSize|sort WorkingSetSize -desc|select -First 180} @()
$procRows=@($proc|%{[ordered]@{pid=$_.ProcessId;parent_pid=$_.ParentProcessId;name=$_.Name;process_path=$_.ExecutablePath;process_owner=$null;command_line_arguments=(CT $_.CommandLine 220);memory_bytes=$_.WorkingSetSize;started_at_utc=S{([System.Management.ManagementDateTimeConverter]::ToDateTime($_.CreationDate)).ToUniversalTime().ToString('o')} $null}})
$soft=S{$x=@();foreach($p in @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*','HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*')){$x+=Get-ItemProperty $p|?{$_.DisplayName}|select @{N='name';E={$_.DisplayName}},@{N='version';E={$_.DisplayVersion}},@{N='publisher';E={$_.Publisher}},@{N='install_date';E={$_.InstallDate}}};$x|sort name -Unique|select -First 850} @()
$procAct=[ordered]@{running_processes=@($procRows|select -First 120);recently_started_processes=@($procRows|?{$_.started_at_utc}|sort started_at_utc -desc|select -First 40);crashed_applications=RE 'Application' @(1000,1002,1005) $d1 24;installed_software=@($soft|select -First 500);recently_installed_or_uninstalled=RE 'Application' @(1033,1034,11707,11724) $d7 32;startup_applications=S{Get-CimInstance Win32_StartupCommand|select -First 80 Name,Command,User,Location} @();scheduled_tasks=S{Get-ScheduledTask|select -First 120 TaskName,TaskPath,State,Author} @();windows_services=@($svc|select -First 220);suspicious_script_or_binary_activity=@($procRows|?{([string]$_.command_line_arguments) -match '(?i)powershell|wscript|cscript|mshta|rundll32|regsvr32'}|select -First 24);resource_hogs=@($procRows|sort memory_bytes -desc|select -First 24)}
$mp=S{Get-MpComputerStatus|select AMServiceEnabled,AntispywareEnabled,AntivirusEnabled,BehaviorMonitorEnabled,RealTimeProtectionEnabled,IoavProtectionEnabled,NISEnabled,IsTamperProtected,AntivirusSignatureLastUpdated,FullScanAge,QuickScanAge} $null
$th=S{Get-MpThreatDetection|sort InitialDetectionTime -desc|select -First 120 ThreatID,ThreatName,SeverityID,ActionSuccess,InitialDetectionTime,LastThreatStatusChangeTime,Resources} @()
$sec=[ordered]@{
 microsoft_defender_status=$mp;third_party_antivirus_status=S{Get-CimInstance -Namespace root/SecurityCenter2 -Class AntivirusProduct|select -First 20 displayName,pathToSignedProductExe,productState,timestamp} @();
 firewall_status=S{Get-NetFirewallProfile|select Name,Enabled,DefaultInboundAction,DefaultOutboundAction,NotifyOnListen} @();
 bitlocker_encryption_status=S{Get-BitLockerVolume|select -First 16 MountPoint,VolumeStatus,ProtectionStatus,EncryptionMethod,AutoUnlockEnabled} @();
 secure_boot_status=S{Confirm-SecureBootUEFI} $null;tpm_presence_and_health=S{Get-Tpm|select TpmPresent,TpmReady,TpmEnabled,TpmActivated,LockedOut,ManufacturerIdTxt} $null;
 windows_update_status=[ordered]@{update_success_events_7d=EC 'Microsoft-Windows-WindowsUpdateClient/Operational' @(19) $d7;update_failure_events_7d=EC 'Microsoft-Windows-WindowsUpdateClient/Operational' @(20,31,34) $d7;missing_patches=S{$u=New-Object -ComObject Microsoft.Update.Session;$s=$u.CreateUpdateSearcher();$s.Online=$false;$r=$s.Search("IsInstalled=0 and Type='Software'");[ordered]@{count=[int]$r.Updates.Count;titles=@($r.Updates|select -First 30|%{$_.Title})}} $null};
 local_admin_accounts=S{Get-LocalGroupMember -Group 'Administrators'|select -First 100 Name,ObjectClass,PrincipalSource} @();password_policy_status=[ordered]@{raw=S{(net accounts)-join "`n"} $null};
 usb_storage_or_external_device_insertions=RE 'Security' @(6416) $d7 70;applocker_or_wdac_policy_status=[ordered]@{applocker_service=S{Get-Service -Name AppIDSvc|select Name,Status,StartType} $null;wdac=S{Get-CimInstance -Namespace root\Microsoft\Windows\DeviceGuard -Class Win32_DeviceGuard|select SecurityServicesConfigured,SecurityServicesRunning,UsermodeCodeIntegrityPolicyEnforcementStatus,CodeIntegrityPolicyEnforcementStatus} $null};
 defender_detection_history=$th;quarantine_or_threat_history=@($th|select -First 80);tamper_protection_status=$(if($mp){$mp.IsTamperProtected}else{$null});real_time_protection_status=$(if($mp){$mp.RealTimeProtectionEnabled}else{$null})
}
$ae=S{Get-WinEvent -FilterHashtable @{LogName='Security';Id=@(4624,4625,4634,4647,4672,4720,4728,4732,4740,4800,4801);StartTime=$d1} -MaxEvents 240} @()
$ok=0;$bad=0;$lock=0;$unlock=0;$local=0;$remote=0;$elev=0;$newU=0;$grp=0;$acct=0;$unusual=@();$fUser=@{};$samples=@()
foreach($e in $ae){$id=[int]$e.Id;$u=ED $e 'TargetUserName';if(-not $u){$u=ED $e 'SubjectUserName'};$lt=0;$x=ED $e 'LogonType';if($x){[void][int]::TryParse($x,[ref]$lt)};$t=([DateTimeOffset]$e.TimeCreated).ToUniversalTime().ToString('o');$h=$e.TimeCreated.Hour;
 if($id -eq 4624){$ok++;if($lt -in @(2,7,11)){$local++};if($lt -in @(3,10)){$remote++};if($h -lt 6 -or $h -gt 20){if($unusual.Count -lt 80){$unusual+=[ordered]@{user_name=$u;occurred_at_utc=$t;logon_type=$lt}}}}
 elseif($id -eq 4625){$bad++;if($u){if(-not $fUser.ContainsKey($u)){$fUser[$u]=0};$fUser[$u]=[int]$fUser[$u]+1}}
 elseif($id -eq 4800){$lock++}elseif($id -eq 4801){$unlock++}elseif($id -eq 4672){$elev++}elseif($id -eq 4720){$newU++}elseif($id -in @(4728,4732)){$grp++}elseif($id -eq 4740){$acct++}
 if($samples.Count -lt 120){$samples+=[ordered]@{event_id=$id;user_name=$u;occurred_at_utc=$t;logon_type=$(if($lt -gt 0){$lt}else{$null})}}
}
$aRows=@($fUser.GetEnumerator()|sort Value -desc|select -First 30|%{[ordered]@{user_name=$_.Key;failed_attempts=$_.Value}})
$auth=[ordered]@{login_events=[ordered]@{successful_logins_24h=$ok;failed_logins_24h=$bad;local_login_count_24h=$local;remote_login_count_24h=$remote};logout_events_24h=EC 'Security' @(4634,4647) $d1;lock_unlock_events_24h=[ordered]@{lock=$lock;unlock=$unlock};user_switching_or_session_activity=RE 'Microsoft-Windows-TerminalServices-LocalSessionManager/Operational' @(21,24,25) $d1 80;elevated_privilege_usage_24h=$elev;new_user_creation_24h=$newU;group_membership_changes_24h=$grp;account_lockouts_24h=$acct;unusual_login_times=$unusual;repeated_failed_attempts_by_user=$aRows;auth_event_samples=$samples}
$low=@($dsk|%{$size=[double]$_.Size;$free=[double]$_.FreeSpace;$p=$(if($size -gt 0){[math]::Round(($free/$size)*100,2)}else{$null});if($p -ne $null -and $p -lt 10){[ordered]@{drive=$_.DeviceID;free_percent=$p;free_bytes=$_.FreeSpace;total_bytes=$_.Size}}}|?{$_})
$fa=S{Get-WinEvent -FilterHashtable @{LogName='Security';Id=@(4663,4660);StartTime=$d1} -MaxEvents 140} @();$imp=@();$del=@();foreach($e in $fa){$o=ED $e 'ObjectName';if(-not $o){continue};$r=[ordered]@{occurred_at_utc=([DateTimeOffset]$e.TimeCreated).ToUniversalTime().ToString('o');event_id=$e.Id;object_name=$o;process_name=ED $e 'ProcessName';user_name=ED $e 'SubjectUserName'};if($o -match '(?i)\\Windows\\System32|\\ProgramData|\\Users\\Public|\\Windows\\Tasks'){if($imp.Count -lt 40){$imp+=$r}};if($e.Id -eq 4660 -and $del.Count -lt 24){$del+=$r}}
$dl=S{Get-ChildItem -Path "$($env:SystemDrive)\Users\*\Downloads" -File -ErrorAction SilentlyContinue|sort LastWriteTime -desc|select -First 120 @{N='path';E={$_.FullName}},@{N='bytes';E={$_.Length}},@{N='last_write_utc';E={([DateTimeOffset]$_.LastWriteTime).ToUniversalTime().ToString('o')}}} @()
$rb=S{$f=Get-ChildItem -Path "$($env:SystemDrive)\`$Recycle.Bin" -File -Recurse -ErrorAction SilentlyContinue;[ordered]@{file_count=@($f).Count;total_bytes=([int64](@($f|Measure-Object Length -Sum).Sum))}} ([ordered]@{file_count=0;total_bytes=0})
$file=[ordered]@{low_disk_alerts=$low;rapid_file_growth=$(if(@($dl).Count -gt 80){@([ordered]@{area='downloads';reason='high_new_file_count';count=@($dl).Count})}else{@()});important_folder_changes=$imp;deleted_critical_files=$del;file_encryption_behavior_patterns=RE 'Microsoft-Windows-Windows Defender/Operational' @(1121,1122,1123,1116) $d7 80;usb_file_copy_activity=@();download_folder_activity=$dl;recycle_bin_anomalies=$rb;large_file_transfer_signals=RE 'Microsoft-Windows-SMBClient/Connectivity' @(30803,30804,30805) $d7 50}
$tcp=S{Get-NetTCPConnection|select -First 180 LocalAddress,LocalPort,RemoteAddress,RemotePort,State,OwningProcess} @();$udp=S{Get-NetUDPEndpoint|select -First 120 LocalAddress,LocalPort,OwningProcess} @()
$dst=@($tcp|?{$_.RemoteAddress -and $_.RemoteAddress -notin @('0.0.0.0','::','127.0.0.1','::1')}|group RemoteAddress|sort Count -desc|select -First 80|%{[ordered]@{remote_ip=$_.Name;connection_count=$_.Count}})
$net=[ordered]@{active_tcp_connections=$tcp;active_udp_endpoints=$udp;remote_ips_and_ports=@($tcp|select -First 200 RemoteAddress,RemotePort,State,OwningProcess);dns_query_activity=RE 'Microsoft-Windows-DNS-Client/Operational' @(3008,3010,3020) $d1 120;gateway=S{Get-NetRoute -DestinationPrefix '0.0.0.0/0'|sort RouteMetric,InterfaceMetric|select -First 8 NextHop,InterfaceAlias,RouteMetric,InterfaceMetric} @();wifi_ssid=S{$w=(& netsh wlan show interfaces)-join "`n";$m=[regex]::Match($w,'^\s*SSID\s*:\s*(.+)$',[Text.RegularExpressions.RegexOptions]::Multiline);if($m.Success){$m.Groups[1].Value.Trim()}else{$null}} $null;vpn_status=[ordered]@{adapters=@($ad|?{([string]$_.InterfaceDescription) -match '(?i)vpn|wireguard|tap|tun|openvpn|fortinet|anyconnect'}|select Name,InterfaceDescription,Status,LinkSpeed)};bytes_sent_received=S{Get-NetAdapterStatistics|select -First 64 Name,ReceivedBytes,SentBytes,ReceivedUnicastPackets,SentUnicastPackets} @();failed_connection_attempts_24h=EC 'Security' @(5152,5157) $d1;frequent_outbound_destinations=@($dst|select -First 40);network_profile=S{Get-NetConnectionProfile|select -First 32 Name,InterfaceAlias,NetworkCategory,IPv4Connectivity,IPv6Connectivity} @();unusual_external_communication=@($dst|?{$_ -and $_.remote_ip -notmatch '^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.|169\.254\.|127\.)'}|select -First 30)}
$cfg=[ordered]@{
 windows_update_policy=S{$wu=Get-ItemProperty -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate' -EA SilentlyContinue;$au=Get-ItemProperty -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU' -EA SilentlyContinue;[ordered]@{wu_server=$wu.WUServer;wu_status_server=$wu.WUStatusServer;target_group=$wu.TargetGroup;au_options=$au.AUOptions;no_auto_update=$au.NoAutoUpdate;scheduled_install_day=$au.ScheduledInstallDay;scheduled_install_time=$au.ScheduledInstallTime}} ([ordered]@{});
 defender_policy=S{Get-MpPreference|select DisableRealtimeMonitoring,MAPSReporting,SubmitSamplesConsent,DisableArchiveScanning,DisableBehaviorMonitoring,DisableIOAVProtection,PUAProtection,ScanScheduleDay,ScanScheduleTime} $null;
 firewall_profile_rules=[ordered]@{profiles=S{Get-NetFirewallProfile|select Name,Enabled,DefaultInboundAction,DefaultOutboundAction} @();enabled_rules_count=S{(Get-NetFirewallRule -Enabled True|Measure-Object).Count} $null};
 key_registry_controls=S{$s=Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System' -EA SilentlyContinue;$t=Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server' -EA SilentlyContinue;[ordered]@{uac_enable_lua=$s.EnableLUA;consent_prompt_behavior_admin=$s.ConsentPromptBehaviorAdmin;local_account_token_filter_policy=$s.LocalAccountTokenFilterPolicy;rdp_deny_connections=$t.fDenyTSConnections}} ([ordered]@{});
 local_group_policy_state=[ordered]@{gpresult_summary=$null};domain_or_intune_join_state=[ordered]@{domain_joined=[bool]$cs.PartOfDomain;domain_name=$(if([bool]$cs.PartOfDomain){$cs.Domain}else{$null});azure_ad_joined=[bool]$aad;dsreg_status_excerpt=$ds};
 time_sync_status=[ordered]@{w32tm_status=S{(w32tm /query /status)-join "`n"} $null};proxy_configuration=[ordered]@{winhttp=S{(netsh winhttp show proxy)-join "`n"} $null;user_proxy=S{$p=Get-ItemProperty 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Internet Settings' -EA SilentlyContinue;[ordered]@{proxy_enable=$p.ProxyEnable;proxy_server=$p.ProxyServer;auto_config_url=$p.AutoConfigURL}} $null};
 dns_configuration=S{Get-DnsClientServerAddress|select -First 80 InterfaceAlias,AddressFamily,ServerAddresses} @();remote_management_state=[ordered]@{winrm_service=S{Get-Service -Name WinRM|select Name,Status,StartType} $null;rdp_enabled=S{((Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server' -EA SilentlyContinue).fDenyTSConnections -eq 0)} $null;powershell_remoting_enabled=S{(Get-Item WSMan:\localhost\Service\Auth\Kerberos -EA SilentlyContinue).Value -ne $null} $null};
 powershell_execution_policy=S{Get-ExecutionPolicy -List|select Scope,ExecutionPolicy} @();installed_certificates=S{@('Cert:\LocalMachine\My','Cert:\LocalMachine\Root','Cert:\LocalMachine\CA','Cert:\CurrentUser\My')|%{[ordered]@{store=$_;count=(Get-ChildItem $_ -EA SilentlyContinue|Measure-Object).Count}}} @();
 device_control_policy_state=[ordered]@{applocker_service=S{Get-Service -Name AppIDSvc|select Name,Status,StartType} $null;wdac_state=S{Get-CimInstance -Namespace root\Microsoft\Windows\DeviceGuard -Class Win32_DeviceGuard|select SecurityServicesConfigured,SecurityServicesRunning,UsermodeCodeIntegrityPolicyEnforcementStatus,CodeIntegrityPolicyEnforcementStatus} $null}
}
$u7=EC 'System' @(6008,41) $d7;$c7=EC 'Application' @(1000,1002,1005) $d7;$f7=EC 'Security' @(4625) $d7;$f24=$important.failed_logins_24h;$wS=EC 'Microsoft-Windows-WindowsUpdateClient/Operational' @(19) $d7;$wF=EC 'Microsoft-Windows-WindowsUpdateClient/Operational' @(20,31,34) $d7;$avg=$(if($f7 -gt 0){[math]::Round($f7/7,2)}else{0});$trend=$(if($f24 -gt ($avg*1.2)){'increasing'}elseif($f24 -lt ($avg*0.8)){'decreasing'}else{'stable'});$inc=[int]$u7+[int]$c7+[int]$wF+[int]$f24+[int](@($th).Count)
$score=100;if($sysHealth.memory_usage_percent -gt 90){$score-=20};if(@($low).Count -gt 0){$score-=20};if($u7 -gt 0){$score-=[math]::Min(20,$u7*3)};if($c7 -gt 0){$score-=[math]::Min(20,$c7)};if($score -lt 0){$score=0}
$smart=[ordered]@{incident_count_per_device=$inc;repeated_reboot_issues_7d=$u7;app_crash_frequency_7d=$c7;patch_failure_count_7d=$wF;update_success_rate_7d=$(if(($wS+$wF)-gt 0){[math]::Round(($wS/($wS+$wF))*100,2)}else{$null});recurring_alerts=@([ordered]@{key='unexpected_shutdowns_7d';count=$u7},[ordered]@{key='app_crashes_7d';count=$c7},[ordered]@{key='patch_failures_7d';count=$wF},[ordered]@{key='failed_logins_24h';count=$f24}|sort count -desc|select -First 12);user_support_history_integrated=$false;device_age_days=S{if($os -and $os.InstallDate){[math]::Round(((Get-Date).ToUniversalTime()-([DateTime]$os.InstallDate).ToUniversalTime()).TotalDays,1)}else{$null}} $null;warranty_data=[ordered]@{available=$false;note='warranty data is vendor-specific and not exposed by default Windows APIs'};health_trend_over_time=[ordered]@{score_0_to_100=$score;interpretation=$(if($score -ge 80){'healthy'}elseif($score -ge 50){'watch'}else{'degraded'})};risk_trend_over_time=[ordered]@{failed_logins_24h=$f24;failed_logins_avg_daily_7d=$avg;trend=$trend}}
$out=[ordered]@{collected_at_utc=[DateTimeOffset]::UtcNow.ToString('o');basic_device_identity=$identity;system_health_and_performance=$sysHealth;windows_event_logs=$eventLogs;process_and_application_activity=$procAct;security_posture=$sec;authentication_and_user_activity=$auth;file_and_storage_activity=$file;network_telemetry=$net;configuration_and_policy_state=$cfg;smart_operational_data=$smart}
[Console]::Out.WriteLine('DMS_JSON::'+($out|ConvertTo-Json -Compress -Depth 12))
""";
}
