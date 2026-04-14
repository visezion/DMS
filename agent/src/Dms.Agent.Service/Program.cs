using Dms.Agent.Core.Jobs;
using Dms.Agent.Core.Runtime;
using Dms.Agent.Core.Telemetry;
using Dms.Agent.Core.Transport;
using Dms.Agent.Service;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

int diagnosticsExitCode = AgentSelfDiagnostics.TryRunCli(args);
if (diagnosticsExitCode != int.MinValue)
{
    Environment.ExitCode = diagnosticsExitCode;
    return;
}

HostApplicationBuilder builder = Host.CreateApplicationBuilder(args);
builder.Configuration.Sources.Clear();
builder.Configuration
    .AddJsonFile("appsettings.json", optional: true, reloadOnChange: false)
    .AddEnvironmentVariables();

builder.Logging.ClearProviders();
builder.Logging.AddEventLog();

builder.Services.AddWindowsService(options => options.ServiceName = "DMS Agent");
builder.Services.AddSingleton<ApiClient>();
builder.Services.AddSingleton<JobProcessor>();
builder.Services.AddSingleton<AutonomousRemediationLoop>();
builder.Services.AddSingleton<StartupRestoreApplier>();
builder.Services.AddSingleton<AgentTamperProtection>();
builder.Services.AddSingleton<BehaviorTelemetryCollector>();
builder.Services.AddHostedService<Worker>();

IHost host = builder.Build();
host.Run();
