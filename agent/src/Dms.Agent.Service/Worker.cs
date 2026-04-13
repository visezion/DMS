using Dms.Agent.Core.Jobs;
using Dms.Agent.Core.Runtime;
using Dms.Agent.Core.Telemetry;
using Dms.Agent.Core.Transport;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using System.Text;

namespace Dms.Agent.Service;

public sealed class Worker(
    ILogger<Worker> logger,
    ApiClient apiClient,
    JobProcessor jobProcessor,
    AutonomousRemediationLoop remediationLoop,
    StartupRestoreApplier startupRestoreApplier,
    AgentTamperProtection tamperProtection,
    BehaviorTelemetryCollector behaviorTelemetryCollector) : BackgroundService
{
    private static readonly string ProgramDataDir = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
    private static readonly string DiagnosticsDir = Path.Combine(ProgramDataDir, "DMS", "Diagnostics");
    private static readonly string LastSuccessPath = Path.Combine(DiagnosticsDir, "last-success.txt");
    private static readonly string LastErrorPath = Path.Combine(DiagnosticsDir, "last-error.txt");
    private static readonly string LastHeartbeatPath = Path.Combine(DiagnosticsDir, "last-heartbeat.txt");
    private static readonly string CommunicationStatePath = Path.Combine(DiagnosticsDir, "communication-state.json");
    private readonly BehaviorEventSpool _behaviorEventSpool = new();

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        Directory.CreateDirectory(DiagnosticsDir);
        try
        {
            var tamperResult = await tamperProtection.ApplyStartupHardeningAsync(stoppingToken);
            if ((bool?) tamperResult.GetValueOrDefault("enabled") == true)
            {
                logger.LogInformation("Tamper protection startup hardening applied.");
            }

            var restoreResult = await startupRestoreApplier.ApplyPendingAsync(stoppingToken);
            if ((bool?) restoreResult.GetValueOrDefault("applied") == true)
            {
                logger.LogInformation("Startup restore manifest applied before check-in.");
            }
            else if ((string?) restoreResult.GetValueOrDefault("error") is { Length: > 0 } restoreError)
            {
                logger.LogWarning("Startup restore manifest apply result: {Error}", restoreError);
            }
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Startup restore manifest apply failed");
        }

        int consecutiveFailureCount = 0;
        while (!stoppingToken.IsCancellationRequested)
        {
            bool cycleSucceeded = false;
            try
            {
                bool completed = false;
                Exception? lastException = null;

                for (int attempt = 1; attempt <= 3 && !stoppingToken.IsCancellationRequested; attempt++)
                {
                    try
                    {
                        var remediation = await remediationLoop.RunOnceAsync(stoppingToken);
                        if ((bool?) remediation.GetValueOrDefault("enabled") == true && (int?) remediation.GetValueOrDefault("executed") > 0)
                        {
                            logger.LogInformation("Autonomous remediation executed {Count} action(s).", remediation["executed"]);
                        }

                        var checkin = await apiClient.CheckinAsync(stoppingToken);
                        DateTimeOffset? verificationNowUtc = checkin.ServerTime == default
                            ? null
                            : checkin.ServerTime.ToUniversalTime();
                        await jobProcessor.ProcessAsync(checkin.Commands, stoppingToken, verificationNowUtc);
                        try
                        {
                            var behaviorEvents = await behaviorTelemetryCollector.CollectAsync(stoppingToken);
                            if (behaviorEvents.Count > 0)
                            {
                                _behaviorEventSpool.Enqueue(behaviorEvents);
                                logger.LogInformation("Queued {Count} behavior telemetry event(s) for upload.", behaviorEvents.Count);
                            }

                            int flushed = await _behaviorEventSpool.FlushAsync(apiClient, stoppingToken);
                            if (flushed > 0)
                            {
                                logger.LogInformation("Uploaded {Count} spooled behavior telemetry event(s).", flushed);
                            }
                        }
                        catch (Exception telemetryEx)
                        {
                            logger.LogWarning(telemetryEx, "Behavior telemetry upload failed");
                        }

                        completed = true;
                        cycleSucceeded = true;
                        consecutiveFailureCount = 0;
                        WriteDiagnosticsFile(LastSuccessPath, $"utc={DateTimeOffset.UtcNow:O}{Environment.NewLine}attempt={attempt}");
                        WriteCommunicationState("online", attempt, consecutiveFailureCount, null);
                        break;
                    }
                    catch (Exception ex)
                    {
                        lastException = ex;
                        logger.LogWarning(ex, "Agent check-in attempt {Attempt} failed", attempt);
                        WriteCommunicationState("degraded", attempt, consecutiveFailureCount + 1, ex.Message);
                        if (attempt < 3) {
                            await Task.Delay(ResolveAttemptRetryDelay(attempt), stoppingToken);
                        }
                    }
                }

                if (!completed && lastException is not null)
                {
                    throw lastException;
                }
            }
            catch (Exception ex)
            {
                consecutiveFailureCount++;
                logger.LogError(ex, "Agent loop failed");
                var message = new StringBuilder()
                    .AppendLine($"utc={DateTimeOffset.UtcNow:O}")
                    .AppendLine($"type={ex.GetType().FullName}")
                    .AppendLine($"message={ex.Message}")
                    .AppendLine($"stack={ex.StackTrace}")
                    .ToString();
                WriteDiagnosticsFile(LastErrorPath, message);
                WriteCommunicationState("offline", 3, consecutiveFailureCount, ex.Message);
            }
            finally
            {
                WriteDiagnosticsFile(LastHeartbeatPath, $"utc={DateTimeOffset.UtcNow:O}");
            }

            int delaySeconds = cycleSucceeded
                ? ResolveCheckinIntervalSeconds()
                : ResolveRecoveryDelaySeconds(consecutiveFailureCount);
            await Task.Delay(TimeSpan.FromSeconds(delaySeconds), stoppingToken);
        }
    }

    private static int ResolveCheckinIntervalSeconds()
    {
        return AgentBootstrapConfiguration.Load().CheckinIntervalSeconds;
    }

    private static TimeSpan ResolveAttemptRetryDelay(int attempt)
    {
        int seconds = Math.Min(15, 3 * Math.Max(1, attempt));
        return TimeSpan.FromSeconds(seconds);
    }

    private static int ResolveRecoveryDelaySeconds(int consecutiveFailureCount)
    {
        int normalized = Math.Max(1, consecutiveFailureCount);
        return Math.Min(60, 5 * normalized);
    }

    private static void WriteCommunicationState(string state, int attempt, int consecutiveFailures, string? lastError)
    {
        string payload = $$"""
        {
          "utc": "{{DateTimeOffset.UtcNow:O}}",
          "state": "{{state}}",
          "attempt": {{attempt}},
          "consecutive_failures": {{consecutiveFailures}},
          "checkin_interval_seconds": {{ResolveCheckinIntervalSeconds()}},
          "last_error": {{System.Text.Json.JsonSerializer.Serialize(lastError)}}
        }
        """;
        WriteDiagnosticsFile(CommunicationStatePath, payload);
    }

    private static void WriteDiagnosticsFile(string path, string content)
    {
        try
        {
            File.WriteAllText(path, content);
        }
        catch
        {
            // Ignore diagnostics write failures.
        }
    }
}
