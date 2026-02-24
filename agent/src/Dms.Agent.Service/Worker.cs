using Dms.Agent.Core.Jobs;
using Dms.Agent.Core.Runtime;
using Dms.Agent.Core.Transport;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using System.Text;

namespace Dms.Agent.Service;

public sealed class Worker(ILogger<Worker> logger, ApiClient apiClient, JobProcessor jobProcessor, AutonomousRemediationLoop remediationLoop) : BackgroundService
{
    private static readonly string ProgramDataDir = Environment.GetEnvironmentVariable("ProgramData") ?? @"C:\ProgramData";
    private static readonly string DiagnosticsDir = Path.Combine(ProgramDataDir, "DMS", "Diagnostics");
    private static readonly string LastSuccessPath = Path.Combine(DiagnosticsDir, "last-success.txt");
    private static readonly string LastErrorPath = Path.Combine(DiagnosticsDir, "last-error.txt");
    private static readonly string LastHeartbeatPath = Path.Combine(DiagnosticsDir, "last-heartbeat.txt");

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        Directory.CreateDirectory(DiagnosticsDir);
        int intervalSeconds = ResolveCheckinIntervalSeconds();

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
                        await jobProcessor.ProcessAsync(checkin.Commands, stoppingToken);

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

            await Task.Delay(TimeSpan.FromSeconds(intervalSeconds), stoppingToken);
        }
    }

    private static int ResolveCheckinIntervalSeconds()
    {
        const int fallback = 60;
        const int min = 15;
        const int max = 300;
        string? raw = Environment.GetEnvironmentVariable("DMS_CHECKIN_INTERVAL_SECONDS");
        if (int.TryParse(raw, out int parsed))
        {
            return Math.Clamp(parsed, min, max);
        }

        return fallback;
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
