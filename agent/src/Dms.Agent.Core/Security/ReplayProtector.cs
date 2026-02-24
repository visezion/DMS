namespace Dms.Agent.Core.Security;

public sealed class ReplayProtector
{
    private readonly HashSet<string> _seen = [];
    private long _lastSequence;

    public void AssertNotReplay(string commandId, string nonce, long sequence)
    {
        string key = $"{commandId}:{nonce}";
        if (_seen.Contains(key) || sequence <= _lastSequence)
        {
            throw new InvalidOperationException("E_REPLAY");
        }

        _seen.Add(key);
        _lastSequence = sequence;
    }
}
