using Dms.Agent.Core.Protocol;
using Dms.Agent.Core.Security;
using Dms.Agent.Core.Transport;

namespace Dms.Agent.Core.Jobs;

public interface IJobHandler
{
    string JobType { get; }
    Task<(string Status, int ExitCode, object? Result)> ExecuteAsync(CommandEnvelopeDto envelope, CancellationToken cancellationToken);
}

public sealed class JobProcessor
{
    private readonly ApiClient _apiClient;
    private readonly CommandEnvelopeVerifier _verifier;
    private readonly Dictionary<string, IJobHandler> _handlers;
    private static readonly bool SignatureDebugEnabled =
        string.Equals(Environment.GetEnvironmentVariable("DMS_SIGNATURE_DEBUG"), "true", StringComparison.OrdinalIgnoreCase);

    public JobProcessor(ApiClient apiClient)
    {
        _apiClient = apiClient;
        _verifier = new CommandEnvelopeVerifier(new ReplayProtector());
        _handlers = new IJobHandler[]
        {
            new Handlers.WingetInstallHandler(),
            new Handlers.WingetUninstallHandler(),
            new Handlers.MsiInstallHandler(),
            new Handlers.MsiUninstallHandler(),
            new Handlers.ExeInstallHandler(),
            new Handlers.CustomInstallHandler(),
            new Handlers.ArchiveInstallHandler(),
            new Handlers.ExeUninstallHandler(),
            new Handlers.ArchiveUninstallHandler(),
            new Handlers.SoftwareInventoryReconcileHandler(),
            new Handlers.PolicyApplyHandler(),
            new Handlers.ScriptHandler(),
            new Handlers.AgentUpdateHandler(),
            new Handlers.AgentUninstallHandler(),
        }.ToDictionary(x => x.JobType, StringComparer.OrdinalIgnoreCase);
    }

    public async Task ProcessAsync(List<SignedCommandDto> commands, CancellationToken cancellationToken)
    {
        _verifier.UpdateKeys(await _apiClient.GetKeysetAsync(cancellationToken));

        foreach (var command in commands)
        {
            try
            {
                _verifier.Verify(command);
                await _apiClient.AckAsync(command.Envelope.CommandId, cancellationToken);

                if (!_handlers.TryGetValue(command.Envelope.Type, out var handler))
                {
                    await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, new { error = ErrorCodes.Unsupported }, cancellationToken);
                    continue;
                }

                var result = await handler.ExecuteAsync(command.Envelope, cancellationToken);
                await _apiClient.ResultAsync(command.Envelope.CommandId, result.Status, result.ExitCode, result.Result, cancellationToken);
            }
            catch (Exception ex)
            {
                if (SignatureDebugEnabled && string.Equals(ex.Message, ErrorCodes.PayloadHash, StringComparison.Ordinal))
                {
                    var diagnostics = _verifier.BuildPayloadHashDiagnostics(command);
                    await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, new
                    {
                        error = ex.Message,
                        payload_hash_debug = diagnostics,
                    }, cancellationToken);
                    continue;
                }

                if (SignatureDebugEnabled && string.Equals(ex.Message, ErrorCodes.SigInvalid, StringComparison.Ordinal))
                {
                    var diagnostics = _verifier.BuildSignatureDiagnostics(command);
                    await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, new
                    {
                        error = ex.Message,
                        signature_debug = diagnostics,
                    }, cancellationToken);
                    continue;
                }

                await _apiClient.ResultAsync(command.Envelope.CommandId, "failed", 1, new { error = ex.Message }, cancellationToken);
            }
        }
    }
}
