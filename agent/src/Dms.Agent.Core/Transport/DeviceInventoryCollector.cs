using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Net.Http.Json;
using System.Text.Json;
using Microsoft.Win32;

namespace Dms.Agent.Core.Transport;

internal static class DeviceInventoryCollector
{
    private static readonly object Sync = new();
    private static DateTimeOffset _lastCollectedAt = DateTimeOffset.MinValue;
    private static Dictionary<string, object?>? _cached;

    private static TimeSpan InventoryCacheTtl()
    {
        var raw = Environment.GetEnvironmentVariable("DMS_INVENTORY_CACHE_SECONDS");
        if (int.TryParse(raw, out int seconds) && seconds >= 0)
        {
            return TimeSpan.FromSeconds(seconds);
        }

        // Keep inventory fairly fresh for dashboard diagnostics by default.
        return TimeSpan.FromSeconds(60);
    }

    public static async Task<Dictionary<string, object?>> CollectAsync(CancellationToken cancellationToken)
    {
        var cacheTtl = InventoryCacheTtl();
        lock (Sync)
        {
            if (_cached is not null && (DateTimeOffset.UtcNow - _lastCollectedAt) < cacheTtl)
            {
                return _cached;
            }
        }

        var data = new Dictionary<string, object?>
        {
            ["collected_at"] = DateTimeOffset.UtcNow.ToString("O"),
            ["device_identity"] = await GetDeviceIdentityAsync(cancellationToken),
            ["cpu"] = GetCpu(),
            ["memory"] = GetMemory(),
            ["disks"] = GetDisks(),
            ["network"] = GetNetwork(),
            ["logged_in_sessions"] = await GetLoggedInSessionsAsync(cancellationToken),
            ["installed_software"] = GetInstalledSoftware(),
            ["running_processes"] = GetProcesses(),
            ["services"] = GetServices(),
            ["geolocation"] = await GetGeoAsync(cancellationToken),
            ["windows_telemetry"] = await WindowsExtendedTelemetryCollector.CollectAsync(cancellationToken),
        };

        lock (Sync)
        {
            _cached = data;
            _lastCollectedAt = DateTimeOffset.UtcNow;
        }
        return data;
    }

    private static Dictionary<string, object?> GetCpu()
    {
        string model = string.Empty;
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"HARDWARE\DESCRIPTION\System\CentralProcessor\0");
            model = key?.GetValue("ProcessorNameString")?.ToString() ?? string.Empty;
        }
        catch { }

        return new Dictionary<string, object?>
        {
            ["model"] = model,
            ["logical_cores"] = Environment.ProcessorCount,
            ["architecture"] = Environment.GetEnvironmentVariable("PROCESSOR_ARCHITECTURE") ?? "unknown",
        };
    }

    private static Dictionary<string, object?> GetMemory()
    {
        try
        {
            var mem = new MEMORYSTATUSEX();
            if (GlobalMemoryStatusEx(mem))
            {
                return new Dictionary<string, object?>
                {
                    ["total_bytes"] = (long) mem.ullTotalPhys,
                    ["available_bytes"] = (long) mem.ullAvailPhys,
                };
            }
        }
        catch { }

        return new Dictionary<string, object?> { ["total_bytes"] = null, ["available_bytes"] = null };
    }

    private static List<Dictionary<string, object?>> GetDisks()
    {
        var list = new List<Dictionary<string, object?>>();
        try
        {
            foreach (DriveInfo d in DriveInfo.GetDrives().Where(x => x.IsReady && x.DriveType == DriveType.Fixed))
            {
                list.Add(new Dictionary<string, object?>
                {
                    ["name"] = d.Name,
                    ["format"] = d.DriveFormat,
                    ["total_bytes"] = d.TotalSize,
                    ["free_bytes"] = d.AvailableFreeSpace,
                });
            }
        }
        catch { }
        return list;
    }

    private static List<Dictionary<string, object?>> GetInstalledSoftware()
    {
        var result = new List<Dictionary<string, object?>>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        string[] uninstallRoots =
        {
            @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
            @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall",
        };

        foreach (string root in uninstallRoots)
        {
            try
            {
                using RegistryKey? key = Registry.LocalMachine.OpenSubKey(root);
                if (key is null) continue;
                foreach (string subName in key.GetSubKeyNames())
                {
                    using RegistryKey? sub = key.OpenSubKey(subName);
                    string name = sub?.GetValue("DisplayName")?.ToString() ?? string.Empty;
                    if (string.IsNullOrWhiteSpace(name)) continue;
                    if (!seen.Add(name)) continue;
                    result.Add(new Dictionary<string, object?>
                    {
                        ["name"] = name,
                        ["version"] = sub?.GetValue("DisplayVersion")?.ToString(),
                        ["publisher"] = sub?.GetValue("Publisher")?.ToString(),
                        ["install_date"] = sub?.GetValue("InstallDate")?.ToString(),
                    });
                    if (result.Count >= 500) return result;
                }
            }
            catch { }
        }

        return result;
    }

    private static List<Dictionary<string, object?>> GetProcesses()
    {
        try
        {
            return Process.GetProcesses()
                .Select(p =>
                {
                    long mem = 0;
                    try { mem = p.WorkingSet64; } catch { }
                    return new { Name = p.ProcessName, Pid = p.Id, Memory = mem };
                })
                .OrderByDescending(p => p.Memory)
                .Take(200)
                .Select(p => new Dictionary<string, object?>
                {
                    ["name"] = p.Name,
                    ["pid"] = p.Pid,
                    ["memory_bytes"] = p.Memory,
                })
                .ToList();
        }
        catch
        {
            return [];
        }
    }

    private static List<Dictionary<string, object?>> GetServices()
    {
        var rows = new List<Dictionary<string, object?>>();
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = "cmd.exe",
                Arguments = "/c sc query state= all",
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
            };
            using var p = Process.Start(psi);
            if (p == null) return rows;
            string output = p.StandardOutput.ReadToEnd();
            p.WaitForExit(5000);

            string? currentName = null;
            foreach (string line in output.Split('\n'))
            {
                string l = line.Trim();
                if (l.StartsWith("SERVICE_NAME:", StringComparison.OrdinalIgnoreCase))
                {
                    currentName = l["SERVICE_NAME:".Length..].Trim();
                    continue;
                }
                if (currentName != null && l.StartsWith("STATE", StringComparison.OrdinalIgnoreCase))
                {
                    string state = l.Contains("RUNNING", StringComparison.OrdinalIgnoreCase) ? "RUNNING"
                        : l.Contains("STOPPED", StringComparison.OrdinalIgnoreCase) ? "STOPPED"
                        : l;
                    rows.Add(new Dictionary<string, object?>
                    {
                        ["name"] = currentName,
                        ["state"] = state,
                    });
                    currentName = null;
                    if (rows.Count >= 300) break;
                }
            }
        }
        catch { }
        return rows;
    }

    private static async Task<List<Dictionary<string, object?>>> GetLoggedInSessionsAsync(CancellationToken cancellationToken)
    {
        var rows = new List<Dictionary<string, object?>>();
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = "cmd.exe",
                Arguments = "/c query user",
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
            };
            using var p = Process.Start(psi);
            if (p == null) return rows;
            string output = await p.StandardOutput.ReadToEndAsync(cancellationToken);
            await p.WaitForExitAsync(cancellationToken);

            foreach (string raw in output.Split('\n').Skip(1))
            {
                string line = raw.Trim();
                if (string.IsNullOrWhiteSpace(line)) continue;
                string normalized = line.Replace(">", "").Trim();
                string[] parts = normalized.Split(' ', StringSplitOptions.RemoveEmptyEntries);
                if (parts.Length < 3) continue;
                rows.Add(new Dictionary<string, object?>
                {
                    ["username"] = parts[0],
                    ["session_name"] = parts.Length > 1 ? parts[1] : null,
                    ["id"] = parts.Length > 2 ? parts[2] : null,
                    ["state"] = parts.Length > 3 ? parts[3] : null,
                });
                if (rows.Count >= 20) break;
            }
        }
        catch { }
        return rows;
    }

    private static Dictionary<string, object?> GetNetwork()
    {
        var adapters = new List<Dictionary<string, object?>>();
        var ips = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        try
        {
            foreach (NetworkInterface nic in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (nic.NetworkInterfaceType == NetworkInterfaceType.Loopback) continue;
                var ipProps = nic.GetIPProperties();
                var addrList = ipProps.UnicastAddresses
                    .Select(a => a.Address.ToString())
                    .Where(a => !string.IsNullOrWhiteSpace(a))
                    .Take(6)
                    .ToList();
                foreach (string ip in addrList) ips.Add(ip);

                adapters.Add(new Dictionary<string, object?>
                {
                    ["name"] = nic.Name,
                    ["description"] = nic.Description,
                    ["status"] = nic.OperationalStatus.ToString(),
                    ["mac"] = nic.GetPhysicalAddress().ToString(),
                    ["ips"] = addrList,
                });
            }
        }
        catch { }

        return new Dictionary<string, object?>
        {
            ["ip_addresses"] = ips.Take(20).ToList(),
            ["adapters"] = adapters.Take(50).ToList(),
        };
    }

    private static async Task<Dictionary<string, object?>> GetDeviceIdentityAsync(CancellationToken cancellationToken)
    {
        string hostname = Environment.MachineName;
        string serialNumber = string.Empty;
        string manufacturer = string.Empty;
        string model = string.Empty;
        string windowsEdition = string.Empty;
        string windowsBuildNumber = string.Empty;
        string biosVersion = string.Empty;
        bool domainJoined = false;

        try
        {
            using RegistryKey? currentVersion = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion");
            windowsEdition = currentVersion?.GetValue("ProductName")?.ToString() ?? string.Empty;
            windowsBuildNumber = currentVersion?.GetValue("CurrentBuildNumber")?.ToString() ?? string.Empty;
        }
        catch { }

        try
        {
            string? output = await RunPowerShellSnippetAsync("""
$ErrorActionPreference='SilentlyContinue'
$cs = Get-CimInstance Win32_ComputerSystem
$bios = Get-CimInstance Win32_BIOS
[Console]::Out.WriteLine([string]$cs.Manufacturer)
[Console]::Out.WriteLine([string]$cs.Model)
[Console]::Out.WriteLine([string]$bios.SerialNumber)
[Console]::Out.WriteLine([string]$bios.SMBIOSBIOSVersion)
[Console]::Out.WriteLine(([bool]($cs.PartOfDomain)))
""", cancellationToken);

            if (!string.IsNullOrWhiteSpace(output))
            {
                string[] lines = output
                    .Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);

                manufacturer = lines.ElementAtOrDefault(0) ?? string.Empty;
                model = lines.ElementAtOrDefault(1) ?? string.Empty;
                serialNumber = lines.ElementAtOrDefault(2) ?? string.Empty;
                biosVersion = lines.ElementAtOrDefault(3) ?? string.Empty;
                domainJoined = bool.TryParse(lines.ElementAtOrDefault(4), out bool parsedDomainJoined) && parsedDomainJoined;
            }
        }
        catch { }

        return new Dictionary<string, object?>
        {
            ["hostname"] = hostname,
            ["serial_number"] = serialNumber,
            ["manufacturer"] = manufacturer,
            ["model"] = model,
            ["windows_edition"] = windowsEdition,
            ["windows_build_number"] = windowsBuildNumber,
            ["bios_uefi_version"] = biosVersion,
            ["domain_joined"] = domainJoined,
            ["azure_ad_joined"] = false,
            ["physical_location"] = Environment.GetEnvironmentVariable("DMS_DEVICE_LOCATION") ?? string.Empty,
        };
    }

    private static async Task<string?> RunPowerShellSnippetAsync(string script, CancellationToken cancellationToken)
    {
        var psi = new ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = $"-NoProfile -NonInteractive -ExecutionPolicy Bypass -Command \"{script.Replace("\"", "\\\"").Replace("\r", string.Empty).Replace("\n", "; ")}\"",
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
        };

        using var process = Process.Start(psi);
        if (process is null)
        {
            return null;
        }

        string output = await process.StandardOutput.ReadToEndAsync(cancellationToken);
        await process.WaitForExitAsync(cancellationToken);

        return process.ExitCode == 0 ? output : null;
    }

    private static async Task<Dictionary<string, object?>?> GetGeoAsync(CancellationToken cancellationToken)
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(4) };
            using var res = await http.GetAsync("http://ip-api.com/json/?fields=status,country,regionName,city,lat,lon,query,timezone", cancellationToken);
            if (!res.IsSuccessStatusCode) return null;
            var json = await res.Content.ReadFromJsonAsync<Dictionary<string, object?>>(cancellationToken: cancellationToken);
            return json;
        }
        catch
        {
            return null;
        }
    }

    [System.Runtime.InteropServices.StructLayout(System.Runtime.InteropServices.LayoutKind.Sequential, CharSet = System.Runtime.InteropServices.CharSet.Auto)]
    private sealed class MEMORYSTATUSEX
    {
        public uint dwLength = (uint)System.Runtime.InteropServices.Marshal.SizeOf<MEMORYSTATUSEX>();
        public uint dwMemoryLoad;
        public ulong ullTotalPhys;
        public ulong ullAvailPhys;
        public ulong ullTotalPageFile;
        public ulong ullAvailPageFile;
        public ulong ullTotalVirtual;
        public ulong ullAvailVirtual;
        public ulong ullAvailExtendedVirtual;
    }

    [System.Runtime.InteropServices.DllImport("kernel32.dll", CharSet = System.Runtime.InteropServices.CharSet.Auto, SetLastError = true)]
    [return: System.Runtime.InteropServices.MarshalAs(System.Runtime.InteropServices.UnmanagedType.Bool)]
    private static extern bool GlobalMemoryStatusEx([System.Runtime.InteropServices.In, System.Runtime.InteropServices.Out] MEMORYSTATUSEX lpBuffer);
}
