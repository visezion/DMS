using System.Windows.Forms;

namespace Dms.Agent.GuiInstaller;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();
        InstallerConfig? config = InstallerConfig.LoadFromSelf();
        if (config == null)
        {
            MessageBox.Show(
                "Installer configuration is missing or invalid.\nPlease download a fresh installer from the DMS console.",
                "Configuration Missing",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
            return;
        }

        Application.Run(new MainForm(config));
    }
}
