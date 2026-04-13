using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using Dms.Agent.Core.Protocol;
using Dms.Agent.Core.Transport;
using Microsoft.MixedReality.WebRTC;

namespace Dms.Agent.Core.Jobs.Handlers;

internal sealed class WebRtcHelperConfig
{
    [JsonPropertyName("session_id")]
    public string SessionId { get; init; } = string.Empty;

    [JsonPropertyName("duration_seconds")]
    public int DurationSeconds { get; init; } = 120;

    [JsonPropertyName("max_dimension")]
    public int MaxDimension { get; init; } = 1920;

    [JsonPropertyName("ice_servers")]
    public List<WebRtcIceServerConfig> IceServers { get; init; } = [];
}

internal sealed class WebRtcIceServerConfig
{
    [JsonPropertyName("urls")]
    public List<string> Urls { get; init; } = [];

    [JsonPropertyName("username")]
    public string? Username { get; init; }

    [JsonPropertyName("credential")]
    public string? Credential { get; init; }
}

public static class WebRtcMediaHelperCli
{
    public const int NotHandledExitCode = int.MinValue + 1;

    public static int TryRun(string[] args)
    {
        if (args.Length == 0 || !string.Equals(args[0], "--webrtc-helper", StringComparison.OrdinalIgnoreCase))
        {
            return NotHandledExitCode;
        }

        string outputPath = string.Empty;
        string errorPath = string.Empty;

        try
        {
            var options = ParseOptions(args);
            string configPath = ReadOption(options, "config");
            outputPath = ReadOption(options, "out");
            errorPath = ReadOption(options, "error");

            if (string.IsNullOrWhiteSpace(configPath) || !File.Exists(configPath))
            {
                throw new InvalidOperationException("webrtc helper requires --config <existing-path>");
            }

            if (string.IsNullOrWhiteSpace(outputPath))
            {
                throw new InvalidOperationException("webrtc helper requires --out <path>");
            }

            string json = File.ReadAllText(configPath);
            WebRtcHelperConfig config = JsonSerializer.Deserialize<WebRtcHelperConfig>(json) ?? new WebRtcHelperConfig();
            var runner = new InteractiveWebRtcMediaSession(new ApiClient(), config);
            var result = runner.RunAsync(CancellationToken.None).GetAwaiter().GetResult();

            string? outputDir = Path.GetDirectoryName(outputPath);
            if (!string.IsNullOrWhiteSpace(outputDir))
            {
                Directory.CreateDirectory(outputDir);
            }

            File.WriteAllText(outputPath, JsonSerializer.Serialize(result));
            TryWriteError(errorPath, string.Empty);
            return 0;
        }
        catch (Exception ex)
        {
            TryWriteError(errorPath, ex.ToString());
            return 1;
        }
    }

    private static Dictionary<string, string> ParseOptions(string[] args)
    {
        var options = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        for (int i = 1; i < args.Length; i++)
        {
            string token = args[i];
            if (!token.StartsWith("--", StringComparison.Ordinal))
            {
                continue;
            }

            string key = token[2..];
            string value = string.Empty;
            if ((i + 1) < args.Length && !args[i + 1].StartsWith("--", StringComparison.Ordinal))
            {
                value = args[i + 1];
                i++;
            }

            options[key] = value;
        }

        return options;
    }

    private static string ReadOption(Dictionary<string, string> options, string key)
    {
        return options.TryGetValue(key, out string? value) ? value : string.Empty;
    }

    private static void TryWriteError(string path, string message)
    {
        if (string.IsNullOrWhiteSpace(path))
        {
            return;
        }

        try
        {
            string? directory = Path.GetDirectoryName(path);
            if (!string.IsNullOrWhiteSpace(directory))
            {
                Directory.CreateDirectory(directory);
            }

            File.WriteAllText(path, message ?? string.Empty, Encoding.UTF8);
        }
        catch
        {
            // Best effort.
        }
    }
}

internal sealed class InteractiveWebRtcMediaSession
{
    private readonly ApiClient _apiClient;
    private readonly WebRtcHelperConfig _config;
    private readonly object _frameSync = new();
    private PeerConnection? _peerConnection;
    private ExternalVideoTrackSource? _videoSource;
    private LocalVideoTrack? _localTrack;
    private long _signalSeq;
    private long _inputSeq;
    private int _signalEvents;
    private int _inputEvents;
    private bool _peerRequestedClose;
    private bool _remoteDescriptionApplied;
    private readonly List<IceCandidate> _pendingCandidates = [];
    private byte[]? _cachedFrameBytes;
    private int _cachedWidth;
    private int _cachedHeight;
    private int _cachedStride;
    private DateTime _cachedFrameAtUtc = DateTime.MinValue;

    public InteractiveWebRtcMediaSession(ApiClient apiClient, WebRtcHelperConfig config)
    {
        _apiClient = apiClient;
        _config = config;
    }

    public async Task<object> RunAsync(CancellationToken cancellationToken)
    {
        if (!RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
        {
            throw new InvalidOperationException("webrtc helper is currently supported on Windows endpoints only");
        }

        string sessionId = (_config.SessionId ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(sessionId))
        {
            throw new InvalidOperationException("webrtc helper config is missing session_id");
        }

        int durationSeconds = Math.Clamp(_config.DurationSeconds, 20, 900);
        DateTime deadlineUtc = DateTime.UtcNow.AddSeconds(durationSeconds);

        var peerConnection = new PeerConnection();
        _peerConnection = peerConnection;
        peerConnection.LocalSdpReadytoSend += message =>
        {
            string type = message.Type == SdpMessageType.Answer ? "answer" : "offer";
            _apiClient.PostRemoteSupportWebRtcSignalAsync(
                sessionId,
                type,
                new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
                {
                    ["type"] = type,
                    ["sdp"] = message.Content,
                },
                cancellationToken).GetAwaiter().GetResult();
        };
        peerConnection.IceCandidateReadytoSend += candidate =>
        {
            _apiClient.PostRemoteSupportWebRtcSignalAsync(
                sessionId,
                "ice-candidate",
                new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
                {
                    ["candidate"] = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
                    {
                        ["candidate"] = candidate.Content,
                        ["sdpMid"] = candidate.SdpMid,
                        ["sdpMLineIndex"] = candidate.SdpMlineIndex,
                    },
                },
                cancellationToken).GetAwaiter().GetResult();
        };
        peerConnection.Connected += () =>
        {
            _apiClient.PostRemoteSupportWebRtcSignalAsync(
                sessionId,
                "status",
                new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
                {
                    ["phase"] = "connected",
                    ["message"] = "webrtc media transport connected",
                },
                cancellationToken).GetAwaiter().GetResult();
        };

        PeerConnectionConfiguration connectionConfig = BuildConnectionConfig(_config);
        await peerConnection.InitializeAsync(connectionConfig, cancellationToken);

        _videoSource = ExternalVideoTrackSource.CreateFromArgb32Callback((in FrameRequest request) =>
        {
            IntPtr frameBuffer = IntPtr.Zero;
            Argb32VideoFrame frame = CaptureFrame(request.TimestampMs, out frameBuffer);
            try
            {
                request.CompleteRequest(frame);
            }
            finally
            {
                if (frameBuffer != IntPtr.Zero)
                {
                    Marshal.FreeHGlobal(frameBuffer);
                }
            }
        });

        _localTrack = LocalVideoTrack.CreateFromSource(_videoSource, new LocalVideoTrackInitConfig
        {
            trackName = "screen"
        });

        Transceiver transceiver = peerConnection.AddTransceiver(MediaKind.Video, new TransceiverInitSettings
        {
            Name = "screen",
            InitialDesiredDirection = Transceiver.Direction.SendOnly,
            StreamIDs = ["dms-remote-support"],
        });
        transceiver.LocalVideoTrack = _localTrack;

        peerConnection.CreateOffer();

        await _apiClient.PostRemoteSupportWebRtcSignalAsync(
            sessionId,
            "status",
            new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
            {
                ["phase"] = "ready",
                ["message"] = "webrtc media helper ready",
            },
            cancellationToken);

        while (!cancellationToken.IsCancellationRequested && DateTime.UtcNow < deadlineUtc)
        {
            await PollSignalsAsync(sessionId, cancellationToken);
            await PollInputsAsync(sessionId, cancellationToken);

            if (_peerRequestedClose)
            {
                break;
            }

            int remainingMs = (int)Math.Max(0, (deadlineUtc - DateTime.UtcNow).TotalMilliseconds);
            if (remainingMs <= 0)
            {
                break;
            }

            await Task.Delay(Math.Min(50, remainingMs), cancellationToken);
        }

        peerConnection.Close();
        _localTrack?.Dispose();
        _videoSource?.Dispose();
        peerConnection.Dispose();

        await _apiClient.PostRemoteSupportWebRtcSignalAsync(
            sessionId,
            "status",
            new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase)
            {
                ["phase"] = "closing",
                ["peer_requested_close"] = _peerRequestedClose,
                ["signal_events"] = _signalEvents,
                ["input_events"] = _inputEvents,
            },
            cancellationToken);

        return new
        {
            message = "webrtc media session completed",
            signal_events = _signalEvents,
            input_events = _inputEvents,
            peer_requested_close = _peerRequestedClose,
            remote_support = new
            {
                session_id = sessionId,
                mode = "webrtc",
            },
        };
    }

    private async Task PollSignalsAsync(string sessionId, CancellationToken cancellationToken)
    {
        RemoteSupportRealtimePollResponseDto signals = await _apiClient.GetRemoteSupportWebRtcSignalsAsync(sessionId, _signalSeq, cancellationToken);
        if (signals.Events.Count == 0)
        {
            return;
        }

        _signalSeq = Math.Max(_signalSeq, signals.LatestSeq);
        foreach (RemoteSupportRealtimeEventDto signal in signals.Events.OrderBy(x => x.Seq))
        {
            _signalEvents++;
            string type = (signal.Type ?? string.Empty).Trim().ToLowerInvariant();

            if (type == "bye")
            {
                _peerRequestedClose = true;
                return;
            }

            if (type == "answer")
            {
                string sdp = TryGetString(signal.Payload, "sdp");
                if (string.IsNullOrWhiteSpace(sdp))
                {
                    continue;
                }

                await _peerConnection!.SetRemoteDescriptionAsync(new SdpMessage
                {
                    Type = SdpMessageType.Answer,
                    Content = sdp,
                });
                _remoteDescriptionApplied = true;

                foreach (IceCandidate candidate in _pendingCandidates)
                {
                    _peerConnection.AddIceCandidate(candidate);
                }
                _pendingCandidates.Clear();

                continue;
            }

            if (type == "ice-candidate")
            {
                IceCandidate? candidate = TryReadIceCandidate(signal.Payload);
                if (candidate is null)
                {
                    continue;
                }

                if (_remoteDescriptionApplied)
                {
                    _peerConnection!.AddIceCandidate(candidate);
                }
                else
                {
                    _pendingCandidates.Add(candidate);
                }
            }
        }
    }

    private async Task PollInputsAsync(string sessionId, CancellationToken cancellationToken)
    {
        RemoteSupportRealtimePollResponseDto inputs = await _apiClient.GetRemoteSupportWebRtcInputsAsync(sessionId, _inputSeq, cancellationToken);
        if (inputs.Events.Count == 0)
        {
            return;
        }

        _inputSeq = Math.Max(_inputSeq, inputs.LatestSeq);
        foreach (RemoteSupportRealtimeEventDto input in inputs.Events.OrderBy(x => x.Seq))
        {
            _inputEvents++;
            RemoteDesktopInputInjector.Apply(input.Type, input.Payload);
        }
    }

    private Argb32VideoFrame CaptureFrame(long timestampMs, out IntPtr frameBuffer)
    {
        lock (_frameSync)
        {
            frameBuffer = IntPtr.Zero;

            // Reuse frames within a short window to limit capture pressure.
            if (_cachedFrameBytes is not null && (DateTime.UtcNow - _cachedFrameAtUtc).TotalMilliseconds < 33)
            {
                return CreateUnmanagedFrameFromCache(out frameBuffer);
            }

            Rectangle virtualBounds = GetVirtualScreenBounds();
            using var source = new Bitmap(virtualBounds.Width, virtualBounds.Height, PixelFormat.Format32bppArgb);
            using (Graphics graphics = Graphics.FromImage(source))
            {
                graphics.CopyFromScreen(virtualBounds.Left, virtualBounds.Top, 0, 0, source.Size, CopyPixelOperation.SourceCopy);
            }

            using Bitmap output = ResizeIfNeeded(source, Math.Clamp(_config.MaxDimension, 640, 3840));
            Rectangle rect = new(0, 0, output.Width, output.Height);
            BitmapData bitmapData = output.LockBits(rect, ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
            try
            {
                int bufferSize = Math.Abs(bitmapData.Stride) * output.Height;
                _cachedFrameBytes = new byte[bufferSize];
                Marshal.Copy(bitmapData.Scan0, _cachedFrameBytes, 0, bufferSize);
                _cachedWidth = output.Width;
                _cachedHeight = output.Height;
                _cachedStride = bitmapData.Stride;
                _cachedFrameAtUtc = DateTime.UtcNow;
            }
            finally
            {
                output.UnlockBits(bitmapData);
            }

            return CreateUnmanagedFrameFromCache(out frameBuffer);
        }
    }

    private Argb32VideoFrame CreateUnmanagedFrameFromCache(out IntPtr frameBuffer)
    {
        if (_cachedFrameBytes is null || _cachedWidth < 1 || _cachedHeight < 1 || _cachedStride == 0)
        {
            throw new InvalidOperationException("no captured frame available");
        }

        frameBuffer = Marshal.AllocHGlobal(_cachedFrameBytes.Length);
        Marshal.Copy(_cachedFrameBytes, 0, frameBuffer, _cachedFrameBytes.Length);

        return new Argb32VideoFrame
        {
            data = frameBuffer,
            width = (uint)_cachedWidth,
            height = (uint)_cachedHeight,
            stride = _cachedStride,
        };
    }

    private static PeerConnectionConfiguration BuildConnectionConfig(WebRtcHelperConfig config)
    {
        PeerConnectionConfiguration connectionConfig = new()
        {
            IceServers = [],
        };

        foreach (WebRtcIceServerConfig iceServer in config.IceServers)
        {
            if (iceServer.Urls.Count == 0)
            {
                continue;
            }

            connectionConfig.IceServers.Add(new IceServer
            {
                Urls = iceServer.Urls,
                TurnUserName = iceServer.Username ?? string.Empty,
                TurnPassword = iceServer.Credential ?? string.Empty,
            });
        }

        return connectionConfig;
    }

    private static string TryGetString(JsonElement payload, string propertyName)
    {
        if (payload.ValueKind != JsonValueKind.Object)
        {
            return string.Empty;
        }

        if (!payload.TryGetProperty(propertyName, out JsonElement property))
        {
            return string.Empty;
        }

        return property.ValueKind == JsonValueKind.String ? property.GetString() ?? string.Empty : property.ToString();
    }

    private static IceCandidate? TryReadIceCandidate(JsonElement payload)
    {
        JsonElement candidateNode = payload;
        if (payload.ValueKind == JsonValueKind.Object && payload.TryGetProperty("candidate", out JsonElement nested))
        {
            candidateNode = nested;
        }

        if (candidateNode.ValueKind != JsonValueKind.Object)
        {
            return null;
        }

        string content = TryGetString(candidateNode, "candidate");
        if (string.IsNullOrWhiteSpace(content))
        {
            return null;
        }

        int sdpMLineIndex = 0;
        if (candidateNode.TryGetProperty("sdpMLineIndex", out JsonElement mline) && mline.ValueKind == JsonValueKind.Number)
        {
            mline.TryGetInt32(out sdpMLineIndex);
        }

        return new IceCandidate
        {
            Content = content,
            SdpMid = TryGetString(candidateNode, "sdpMid"),
            SdpMlineIndex = sdpMLineIndex,
        };
    }

    private static Bitmap ResizeIfNeeded(Bitmap source, int maxDimension)
    {
        int sourceMax = Math.Max(source.Width, source.Height);
        if (sourceMax <= maxDimension)
        {
            return (Bitmap)source.Clone();
        }

        double scale = (double)maxDimension / sourceMax;
        int width = Math.Max(1, (int)Math.Round(source.Width * scale));
        int height = Math.Max(1, (int)Math.Round(source.Height * scale));
        var resized = new Bitmap(width, height, PixelFormat.Format32bppArgb);
        using Graphics graphics = Graphics.FromImage(resized);
        graphics.InterpolationMode = InterpolationMode.HighQualityBicubic;
        graphics.SmoothingMode = SmoothingMode.HighQuality;
        graphics.PixelOffsetMode = PixelOffsetMode.HighQuality;
        graphics.CompositingQuality = CompositingQuality.HighQuality;
        graphics.DrawImage(source, 0, 0, width, height);
        return resized;
    }

    private static Rectangle GetVirtualScreenBounds()
    {
        int left = GetSystemMetrics(SmXVirtualScreen);
        int top = GetSystemMetrics(SmYVirtualScreen);
        int width = GetSystemMetrics(SmCxVirtualScreen);
        int height = GetSystemMetrics(SmCyVirtualScreen);
        return new Rectangle(left, top, width, height);
    }

    [DllImport("user32.dll")]
    private static extern int GetSystemMetrics(int index);

    private const int SmXVirtualScreen = 76;
    private const int SmYVirtualScreen = 77;
    private const int SmCxVirtualScreen = 78;
    private const int SmCyVirtualScreen = 79;
}

internal static class RemoteDesktopInputInjector
{
    public static void Apply(string eventType, JsonElement payload)
    {
        switch ((eventType ?? string.Empty).Trim().ToLowerInvariant())
        {
            case "mouse_move":
                SendMouseMove(ReadDouble(payload, "x"), ReadDouble(payload, "y"));
                break;
            case "mouse_down":
                SendMouseButton(ReadInt(payload, "button"), true);
                break;
            case "mouse_up":
                SendMouseButton(ReadInt(payload, "button"), false);
                break;
            case "wheel":
                SendWheel(ReadInt(payload, "delta_y"));
                break;
            case "key_down":
                SendKey(ReadString(payload, "code"), ReadString(payload, "key"), true);
                break;
            case "key_up":
                SendKey(ReadString(payload, "code"), ReadString(payload, "key"), false);
                break;
        }
    }

    private static void SendMouseMove(double normalizedX, double normalizedY)
    {
        Rectangle virtualBounds = GetVirtualScreenBounds();
        int absoluteX = Math.Clamp((int)Math.Round(normalizedX * 65535d), 0, 65535);
        int absoluteY = Math.Clamp((int)Math.Round(normalizedY * 65535d), 0, 65535);

        INPUT[] inputs =
        [
            new INPUT
            {
                type = InputMouse,
                U = new InputUnion
                {
                    mi = new MOUSEINPUT
                    {
                        dx = absoluteX,
                        dy = absoluteY,
                        dwFlags = MouseeventfMove | MouseeventfAbsolute | MouseeventfVirtualdesk,
                    }
                }
            }
        ];

        _ = virtualBounds;
        SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<INPUT>());
    }

    private static void SendMouseButton(int button, bool isDown)
    {
        uint flag = button switch
        {
            2 => isDown ? MouseeventfRightdown : MouseeventfRightup,
            1 => isDown ? MouseeventfMiddledown : MouseeventfMiddleup,
            _ => isDown ? MouseeventfLeftdown : MouseeventfLeftup,
        };

        INPUT[] inputs =
        [
            new INPUT
            {
                type = InputMouse,
                U = new InputUnion
                {
                    mi = new MOUSEINPUT
                    {
                        dwFlags = flag,
                    }
                }
            }
        ];

        SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<INPUT>());
    }

    private static void SendWheel(int deltaY)
    {
        INPUT[] inputs =
        [
            new INPUT
            {
                type = InputMouse,
                U = new InputUnion
                {
                    mi = new MOUSEINPUT
                    {
                        mouseData = (uint)(-deltaY),
                        dwFlags = MouseeventfWheel,
                    }
                }
            }
        ];

        SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<INPUT>());
    }

    private static void SendKey(string code, string key, bool isDown)
    {
        ushort virtualKey = ResolveVirtualKey(code, key);
        if (virtualKey == 0)
        {
            return;
        }

        INPUT[] inputs =
        [
            new INPUT
            {
                type = InputKeyboard,
                U = new InputUnion
                {
                    ki = new KEYBDINPUT
                    {
                        wVk = virtualKey,
                        dwFlags = isDown ? 0u : KeyeventfKeyup,
                    }
                }
            }
        ];

        SendInput((uint)inputs.Length, inputs, Marshal.SizeOf<INPUT>());
    }

    private static ushort ResolveVirtualKey(string code, string key)
    {
        if (code.StartsWith("Key", StringComparison.OrdinalIgnoreCase) && code.Length == 4)
        {
            return (ushort)char.ToUpperInvariant(code[3]);
        }

        if (code.StartsWith("Digit", StringComparison.OrdinalIgnoreCase) && code.Length == 6)
        {
            return (ushort)code[5];
        }

        return code switch
        {
            "Enter" => 0x0D,
            "Escape" => 0x1B,
            "Tab" => 0x09,
            "Backspace" => 0x08,
            "Space" => 0x20,
            "ShiftLeft" => 0x10,
            "ShiftRight" => 0x10,
            "ControlLeft" => 0x11,
            "ControlRight" => 0x11,
            "AltLeft" => 0x12,
            "AltRight" => 0x12,
            "MetaLeft" => 0x5B,
            "MetaRight" => 0x5C,
            "ArrowLeft" => 0x25,
            "ArrowUp" => 0x26,
            "ArrowRight" => 0x27,
            "ArrowDown" => 0x28,
            "Delete" => 0x2E,
            "Insert" => 0x2D,
            "Home" => 0x24,
            "End" => 0x23,
            "PageUp" => 0x21,
            "PageDown" => 0x22,
            "F1" => 0x70,
            "F2" => 0x71,
            "F3" => 0x72,
            "F4" => 0x73,
            "F5" => 0x74,
            "F6" => 0x75,
            "F7" => 0x76,
            "F8" => 0x77,
            "F9" => 0x78,
            "F10" => 0x79,
            "F11" => 0x7A,
            "F12" => 0x7B,
            _ when key.Length == 1 => (ushort)char.ToUpperInvariant(key[0]),
            _ => 0,
        };
    }

    private static string ReadString(JsonElement payload, string propertyName)
    {
        if (payload.ValueKind != JsonValueKind.Object || !payload.TryGetProperty(propertyName, out JsonElement node))
        {
            return string.Empty;
        }

        return node.ValueKind == JsonValueKind.String ? node.GetString() ?? string.Empty : node.ToString();
    }

    private static int ReadInt(JsonElement payload, string propertyName)
    {
        if (payload.ValueKind != JsonValueKind.Object || !payload.TryGetProperty(propertyName, out JsonElement node))
        {
            return 0;
        }

        if (node.ValueKind == JsonValueKind.Number && node.TryGetInt32(out int value))
        {
            return value;
        }

        return int.TryParse(node.ToString(), out value) ? value : 0;
    }

    private static double ReadDouble(JsonElement payload, string propertyName)
    {
        if (payload.ValueKind != JsonValueKind.Object || !payload.TryGetProperty(propertyName, out JsonElement node))
        {
            return 0d;
        }

        if (node.ValueKind == JsonValueKind.Number && node.TryGetDouble(out double value))
        {
            return value;
        }

        return double.TryParse(node.ToString(), out value) ? value : 0d;
    }

    private static Rectangle GetVirtualScreenBounds()
    {
        int left = GetSystemMetrics(SmXVirtualScreen);
        int top = GetSystemMetrics(SmYVirtualScreen);
        int width = GetSystemMetrics(SmCxVirtualScreen);
        int height = GetSystemMetrics(SmCyVirtualScreen);
        return new Rectangle(left, top, width, height);
    }

    [DllImport("user32.dll")]
    private static extern uint SendInput(uint numberOfInputs, INPUT[] inputs, int sizeOfInputStructure);

    [DllImport("user32.dll")]
    private static extern int GetSystemMetrics(int index);

    private const int SmXVirtualScreen = 76;
    private const int SmYVirtualScreen = 77;
    private const int SmCxVirtualScreen = 78;
    private const int SmCyVirtualScreen = 79;
    private const uint InputMouse = 0;
    private const uint InputKeyboard = 1;
    private const uint MouseeventfMove = 0x0001;
    private const uint MouseeventfLeftdown = 0x0002;
    private const uint MouseeventfLeftup = 0x0004;
    private const uint MouseeventfRightdown = 0x0008;
    private const uint MouseeventfRightup = 0x0010;
    private const uint MouseeventfMiddledown = 0x0020;
    private const uint MouseeventfMiddleup = 0x0040;
    private const uint MouseeventfWheel = 0x0800;
    private const uint MouseeventfAbsolute = 0x8000;
    private const uint MouseeventfVirtualdesk = 0x4000;
    private const uint KeyeventfKeyup = 0x0002;

    [StructLayout(LayoutKind.Sequential)]
    private struct INPUT
    {
        public uint type;
        public InputUnion U;
    }

    [StructLayout(LayoutKind.Explicit)]
    private struct InputUnion
    {
        [FieldOffset(0)]
        public MOUSEINPUT mi;

        [FieldOffset(0)]
        public KEYBDINPUT ki;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct MOUSEINPUT
    {
        public int dx;
        public int dy;
        public uint mouseData;
        public uint dwFlags;
        public uint time;
        public nint dwExtraInfo;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct KEYBDINPUT
    {
        public ushort wVk;
        public ushort wScan;
        public uint dwFlags;
        public uint time;
        public nint dwExtraInfo;
    }
}
