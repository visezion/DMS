using System;
using Dms.Agent.Core.Security;
using Xunit;

namespace Dms.Agent.Tests;

public class ReplayProtectionTests
{
    [Fact]
    public void RejectsReplayByNonceAndSequence()
    {
        var protector = new ReplayProtector();
        protector.AssertNotReplay("c1", "n1", 1);

        Assert.Throws<InvalidOperationException>(() => protector.AssertNotReplay("c1", "n1", 2));
        Assert.Throws<InvalidOperationException>(() => protector.AssertNotReplay("c2", "n2", 1));
    }
}
