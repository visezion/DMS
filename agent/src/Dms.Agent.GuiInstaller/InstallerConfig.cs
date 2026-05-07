using System.Text;
using System.Text.Json;

namespace Dms.Agent.GuiInstaller;

internal sealed class InstallerConfig
{
    public string DownloadUrl { get; set; } = string.Empty;
    public string ApiBaseUrl { get; set; } = string.Empty;
    public string Token { get; set; } = string.Empty;
    public string FileName { get; set; } = string.Empty;
    public string ReleaseVersion { get; set; } = string.Empty;

    public static InstallerConfig? LoadFromSelf()
    {
        foreach (string path in ResolveCandidatePaths())
        {
            InstallerConfig? config = TryLoadFromPath(path);
            if (config != null)
            {
                return config;
            }
        }

        return null;
    }

    private static IEnumerable<string> ResolveCandidatePaths()
    {
        var paths = new List<string>();
        if (!string.IsNullOrWhiteSpace(Environment.ProcessPath))
        {
            paths.Add(Environment.ProcessPath);
        }
        string? entry = System.Reflection.Assembly.GetEntryAssembly()?.Location;
        if (!string.IsNullOrWhiteSpace(entry))
        {
            paths.Add(entry);
        }
        try
        {
            string mainModule = System.Diagnostics.Process.GetCurrentProcess().MainModule?.FileName ?? string.Empty;
            if (!string.IsNullOrWhiteSpace(mainModule))
            {
                paths.Add(mainModule);
            }
        }
        catch
        {
            // ignore
        }
        string[] args = Environment.GetCommandLineArgs();
        if (args.Length > 0 && !string.IsNullOrWhiteSpace(args[0]))
        {
            paths.Add(args[0]);
        }

        return paths.Distinct(StringComparer.OrdinalIgnoreCase);
    }

    private static InstallerConfig? TryLoadFromPath(string path)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(path) || !File.Exists(path))
            {
                return null;
            }

            byte[] marker = Encoding.UTF8.GetBytes("DMSCFG:");
            using FileStream stream = new(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite);
            long markerOffset = FindMarkerOffset(stream, marker);
            if (markerOffset < 0)
            {
                return null;
            }

            stream.Seek(markerOffset + marker.Length, SeekOrigin.Begin);
            string lenRaw = ReadLineAscii(stream, 32);
            if (!int.TryParse(lenRaw.Trim(), out int jsonLength) || jsonLength <= 0)
            {
                return null;
            }

            byte[] jsonBytes = new byte[jsonLength];
            int readTotal = 0;
            while (readTotal < jsonLength)
            {
                int read = stream.Read(jsonBytes, readTotal, jsonLength - readTotal);
                if (read <= 0)
                {
                    break;
                }
                readTotal += read;
            }
            if (readTotal != jsonLength)
            {
                return null;
            }

            string json = Encoding.UTF8.GetString(jsonBytes);
            var options = new JsonSerializerOptions { PropertyNameCaseInsensitive = true };
            InstallerConfig? config = JsonSerializer.Deserialize<InstallerConfig>(json, options);
            if (config == null)
            {
                return null;
            }

            config.DownloadUrl = config.DownloadUrl.Trim();
            config.ApiBaseUrl = config.ApiBaseUrl.Trim();
            config.Token = config.Token.Trim();
            config.FileName = config.FileName.Trim();
            config.ReleaseVersion = config.ReleaseVersion.Trim();

            if (string.IsNullOrWhiteSpace(config.DownloadUrl) || string.IsNullOrWhiteSpace(config.ApiBaseUrl))
            {
                return null;
            }

            return config;
        }
        catch
        {
            return null;
        }
    }

    private static long FindMarkerOffset(FileStream stream, byte[] marker)
    {
        const int bufferSize = 1024 * 1024;
        byte[] buffer = new byte[bufferSize];
        byte[] carry = new byte[marker.Length];
        int carryLen = 0;
        long position = 0;
        long lastFound = -1;

        stream.Seek(0, SeekOrigin.Begin);
        int read;
        while ((read = stream.Read(buffer, 0, buffer.Length)) > 0)
        {
            int totalLen = carryLen + read;
            byte[] data = new byte[totalLen];
            if (carryLen > 0)
            {
                Buffer.BlockCopy(carry, 0, data, 0, carryLen);
            }
            Buffer.BlockCopy(buffer, 0, data, carryLen, read);

            int searchFrom = 0;
            while (searchFrom <= data.Length - marker.Length)
            {
                int idx = IndexOfSequence(data, marker, searchFrom);
                if (idx < 0)
                {
                    break;
                }
                lastFound = position - carryLen + idx;
                searchFrom = idx + 1;
            }

            carryLen = Math.Min(marker.Length - 1, totalLen);
            if (carryLen > 0)
            {
                Buffer.BlockCopy(data, totalLen - carryLen, carry, 0, carryLen);
            }
            position += read;
        }

        return lastFound;
    }

    private static int IndexOfSequence(byte[] data, byte[] needle)
    {
        return IndexOfSequence(data, needle, 0);
    }

    private static int IndexOfSequence(byte[] data, byte[] needle, int startIndex)
    {
        if (needle.Length == 0 || data.Length < needle.Length)
        {
            return -1;
        }

        if (startIndex < 0)
        {
            startIndex = 0;
        }
        for (int i = startIndex; i <= data.Length - needle.Length; i++)
        {
            bool match = true;
            for (int j = 0; j < needle.Length; j++)
            {
                if (data[i + j] != needle[j])
                {
                    match = false;
                    break;
                }
            }
            if (match)
            {
                return i;
            }
        }

        return -1;
    }

    private static string ReadLineAscii(FileStream stream, int maxBytes)
    {
        List<byte> bytes = new();
        for (int i = 0; i < maxBytes; i++)
        {
            int b = stream.ReadByte();
            if (b < 0)
            {
                break;
            }
            if (b == '\n' || b == '\r')
            {
                if (b == '\r')
                {
                    int peek = stream.ReadByte();
                    if (peek != '\n' && peek >= 0)
                    {
                        stream.Seek(-1, SeekOrigin.Current);
                    }
                }
                break;
            }
            bytes.Add((byte)b);
        }

        return Encoding.UTF8.GetString(bytes.ToArray());
    }

    private static int LastIndexOf(byte[] data, byte[] needle)
    {
        if (needle.Length == 0 || data.Length < needle.Length)
        {
            return -1;
        }

        for (int i = data.Length - needle.Length; i >= 0; i--)
        {
            bool match = true;
            for (int j = 0; j < needle.Length; j++)
            {
                if (data[i + j] != needle[j])
                {
                    match = false;
                    break;
                }
            }
            if (match)
            {
                return i;
            }
        }

        return -1;
    }
}
