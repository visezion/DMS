using System;
using System.Reflection;
using Dms.Agent.Core.Jobs.Handlers;
using Xunit;

namespace Dms.Agent.Tests;

public class TimePolicyRuleTests
{
    [Theory]
    [InlineData("manual", "manual")]
    [InlineData("NTP", "manual")]
    [InlineData("nt5ds", "domhier")]
    [InlineData("domhier", "domhier")]
    [InlineData("all", "all")]
    public void NormalizeTimeSyncMode_MapsWindowsTimeServiceModes(string input, string expected)
    {
        MethodInfo? method = typeof(PolicyApplyHandler)
            .Assembly
            .GetType("Dms.Agent.Core.Jobs.Handlers.PolicyApplyHandler")?
            .GetMethod("NormalizeTimeSyncMode", BindingFlags.NonPublic | BindingFlags.Static);

        Assert.NotNull(method);

        string actual = (string) method!.Invoke(null, new object[] { input })!;
        Assert.Equal(expected, actual);
    }
}
