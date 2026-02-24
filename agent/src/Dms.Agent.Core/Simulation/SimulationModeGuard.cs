namespace Dms.Agent.Core.Simulation;

public static class SimulationModeGuard
{
    public static bool IsEnabled()
    {
        string? value = Environment.GetEnvironmentVariable("DMS_SIMULATION_MODE");
        return string.Equals(value, "true", StringComparison.OrdinalIgnoreCase);
    }
}
