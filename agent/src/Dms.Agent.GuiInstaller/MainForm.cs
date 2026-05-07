using System.Drawing;
using System.Text;
using System.Windows.Forms;

namespace Dms.Agent.GuiInstaller;

internal sealed class MainForm : Form
{
    private readonly InstallerConfig _config;
    private readonly Label _statusLabel;
    private readonly ProgressBar _progress;
    private readonly TextBox _logBox;
    private readonly Button _installButton;
    private readonly Button _closeButton;

    public MainForm(InstallerConfig config)
    {
        _config = config;
        Font = new Font("Segoe UI", 9F);
        Text = "DMS Agent Installer";
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;
        ClientSize = new Size(720, 520);
        BackColor = Color.White;

        var header = new Panel
        {
            Dock = DockStyle.Top,
            Height = 90,
            BackColor = Color.FromArgb(15, 118, 110)
        };
        var title = new Label
        {
            Text = "DMS Agent Installer",
            ForeColor = Color.White,
            Font = new Font("Segoe UI", 18F, FontStyle.Bold),
            Location = new Point(24, 18),
            AutoSize = true
        };
        var subtitle = new Label
        {
            Text = "Modern guided setup for Windows endpoints",
            ForeColor = Color.FromArgb(215, 248, 246),
            Font = new Font("Segoe UI", 10F),
            Location = new Point(26, 52),
            AutoSize = true
        };
        header.Controls.Add(title);
        header.Controls.Add(subtitle);

        var infoPanel = new Panel
        {
            Dock = DockStyle.Top,
            Height = 70,
            BackColor = Color.FromArgb(245, 248, 250),
            Padding = new Padding(24, 12, 24, 12)
        };
        var releaseLabel = new Label
        {
            Text = $"Release: {(string.IsNullOrWhiteSpace(_config.ReleaseVersion) ? "latest" : _config.ReleaseVersion)}",
            ForeColor = Color.FromArgb(30, 41, 59),
            Font = new Font("Segoe UI", 10F, FontStyle.Bold),
            AutoSize = true
        };
        var apiLabel = new Label
        {
            Text = $"API Base URL: {_config.ApiBaseUrl}",
            ForeColor = Color.FromArgb(71, 85, 105),
            Font = new Font("Segoe UI", 9F),
            AutoSize = true,
            Location = new Point(0, 28)
        };
        infoPanel.Controls.Add(releaseLabel);
        infoPanel.Controls.Add(apiLabel);

        var contentPanel = new Panel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(24, 18, 24, 18)
        };

        var stepsLabel = new Label
        {
            Text = "Steps",
            Font = new Font("Segoe UI", 11F, FontStyle.Bold),
            ForeColor = Color.FromArgb(30, 41, 59),
            AutoSize = true,
            Location = new Point(0, 0)
        };
        var stepsText = new Label
        {
            Text = "1. Prepare enrollment token\n2. Download agent package\n3. Install and verify service",
            ForeColor = Color.FromArgb(71, 85, 105),
            Font = new Font("Segoe UI", 9F),
            AutoSize = true,
            Location = new Point(0, 28)
        };

        _statusLabel = new Label
        {
            Text = "Ready to install.",
            ForeColor = Color.FromArgb(15, 118, 110),
            Font = new Font("Segoe UI", 10F, FontStyle.Bold),
            AutoSize = true,
            Location = new Point(0, 94)
        };

        _progress = new ProgressBar
        {
            Location = new Point(0, 126),
            Size = new Size(640, 18),
            Minimum = 0,
            Maximum = 100,
            Value = 0
        };

        _logBox = new TextBox
        {
            Location = new Point(0, 162),
            Size = new Size(640, 180),
            Multiline = true,
            ReadOnly = true,
            ScrollBars = ScrollBars.Vertical,
            BorderStyle = BorderStyle.FixedSingle,
            BackColor = Color.White
        };

        _installButton = new Button
        {
            Text = "Install Agent",
            BackColor = Color.FromArgb(14, 116, 144),
            ForeColor = Color.White,
            FlatStyle = FlatStyle.Flat,
            Location = new Point(0, 360),
            Size = new Size(140, 36)
        };
        _installButton.FlatAppearance.BorderSize = 0;
        _installButton.Click += async (_, _) => await StartInstallAsync();

        _closeButton = new Button
        {
            Text = "Close",
            BackColor = Color.White,
            ForeColor = Color.FromArgb(51, 65, 85),
            FlatStyle = FlatStyle.Flat,
            Location = new Point(150, 360),
            Size = new Size(120, 36)
        };
        _closeButton.FlatAppearance.BorderColor = Color.FromArgb(203, 213, 225);
        _closeButton.Click += (_, _) => Close();

        contentPanel.Controls.Add(stepsLabel);
        contentPanel.Controls.Add(stepsText);
        contentPanel.Controls.Add(_statusLabel);
        contentPanel.Controls.Add(_progress);
        contentPanel.Controls.Add(_logBox);
        contentPanel.Controls.Add(_installButton);
        contentPanel.Controls.Add(_closeButton);

        Controls.Add(contentPanel);
        Controls.Add(infoPanel);
        Controls.Add(header);
    }

    private async Task StartInstallAsync()
    {
        _installButton.Enabled = false;
        _closeButton.Enabled = false;
        AppendLog("Starting installation...");

        var progress = new Progress<InstallerProgress>(UpdateProgress);
        try
        {
            var runner = new InstallerRunner(_config, AppendLog);
            await runner.RunAsync(progress, CancellationToken.None);
            _statusLabel.Text = "Installation complete.";
            _progress.Value = 100;
            AppendLog("Installation completed successfully.");
        }
        catch (Exception ex)
        {
            _statusLabel.Text = "Installation failed.";
            AppendLog("Error: " + ex.Message);
            MessageBox.Show($"Installation failed:\n{ex.Message}", "Install Failed", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
        finally
        {
            _closeButton.Enabled = true;
            _closeButton.Text = "Close";
        }
    }

    private void UpdateProgress(InstallerProgress progress)
    {
        if (progress.Percent >= 0 && progress.Percent <= 100)
        {
            _progress.Value = progress.Percent;
        }
        if (!string.IsNullOrWhiteSpace(progress.Message))
        {
            _statusLabel.Text = progress.Message;
        }
    }

    private void AppendLog(string message)
    {
        string line = $"[{DateTime.Now:HH:mm:ss}] {message}";
        if (_logBox.InvokeRequired)
        {
            _logBox.BeginInvoke(new Action(() => AppendLog(message)));
            return;
        }
        _logBox.AppendText(line + Environment.NewLine);
    }
}
