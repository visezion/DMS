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

        while (!stoppingToken.IsCancellationRequested)
        {
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
                        WriteDiagnosticsFile(LastSuccessPath, $"utc={DateTimeOffset.UtcNow:O}{Environment.NewLine}attempt={attempt}");
                        break;
                    }
                    catch (Exception ex)
                    {
                        lastException = ex;
                        logger.LogWarning(ex, "Agent check-in attempt {Attempt} failed", attempt);
                        if (attempt < 3) {
                            await Task.Delay(TimeSpan.FromSeconds(10), stoppingToken);
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
                logger.LogError(ex, "Agent loop failed");
                var message = new StringBuilder()
                    .AppendLine($"utc={DateTimeOffset.UtcNow:O}")
                    .AppendLine($"type={ex.GetType().FullName}")
                    .AppendLine($"message={ex.Message}")
                    .AppendLine($"stack={ex.StackTrace}")
                    .ToString();
                WriteDiagnosticsFile(LastErrorPath, message);
            }
            finally
            {
                WriteDiagnosticsFile(LastHeartbeatPath, $"utc={DateTimeOffset.UtcNow:O}");
            }

            await Task.Delay(TimeSpan.FromSeconds(ResolveCheckinIntervalSeconds()), stoppingToken);
        }
    }

    private static int ResolveCheckinIntervalSeconds()
    {
        return AgentBootstrapConfiguration.Load().CheckinIntervalSeconds;
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
