Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$workerScript = Join-Path $scriptRoot "install-agent-by-smb-rpc.ps1"

if (-not (Test-Path -Path $workerScript -PathType Leaf)) {
    [System.Windows.Forms.MessageBox]::Show(
        "Missing worker script:`n$workerScript",
        "DMS SMB/RPC Installer",
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null
    exit 1
}

$script:proc = $null
$script:totalTargets = 0
$script:processedTargets = 0
$script:okTargets = 0
$script:failedTargets = 0
$script:reportPath = ""

function Invoke-Ui {
    param([scriptblock]$Action)
    if ($form.IsHandleCreated) {
        $form.BeginInvoke([System.Windows.Forms.MethodInvoker]$Action) | Out-Null
    }
}

function Escape-Arg {
    param([string]$Value)
    return '"' + ($Value -replace '"', '\"') + '"'
}

function Append-Log {
    param([string]$Line)
    if ([string]::IsNullOrWhiteSpace($Line)) { return }
    Invoke-Ui {
        $txtLog.AppendText($Line + [Environment]::NewLine)
        $txtLog.SelectionStart = $txtLog.TextLength
        $txtLog.ScrollToCaret()
    }
}

function Update-Counters {
    Invoke-Ui {
        $lblTotal.Text = "Found: $script:totalTargets"
        $lblProcessed.Text = "Processed: $script:processedTargets"
        $lblSuccess.Text = "Installed: $script:okTargets"
        $lblFailed.Text = "Failed: $script:failedTargets"
        if ($script:totalTargets -gt 0) {
            $progress.Maximum = $script:totalTargets
            $value = [Math]::Min($script:processedTargets, $script:totalTargets)
            $progress.Value = [Math]::Max(0, $value)
        } else {
            $progress.Value = 0
        }
    }
}

function Parse-OutputLine {
    param([string]$Line)
    Append-Log -Line $Line

    if ($Line -match '^Targets:\s+(\d+)$') {
        $script:totalTargets = [int]$matches[1]
        Update-Counters
        return
    }

    if ($Line -match '^\[(.+?)\]\s+success') {
        $ip = $matches[1]
        $script:processedTargets++
        $script:okTargets++
        Invoke-Ui { $lblCurrent.Text = "Current: $ip success" }
        Update-Counters
        return
    }

    if ($Line -match '^\[(.+?)\]\s+failed') {
        $ip = $matches[1]
        $script:processedTargets++
        $script:failedTargets++
        Invoke-Ui { $lblCurrent.Text = "Current: $ip failed" }
        Update-Counters
        return
    }

    if ($Line -match '^Report:\s+(.+)$') {
        $script:reportPath = $matches[1].Trim()
        Invoke-Ui { $lblReport.Text = "Report: $script:reportPath" }
        return
    }
}

function Reset-State {
    $script:totalTargets = 0
    $script:processedTargets = 0
    $script:okTargets = 0
    $script:failedTargets = 0
    $script:reportPath = ""
    $lblCurrent.Text = "Current: -"
    $lblReport.Text = "Report: -"
    Update-Counters
}

function Set-RunningState {
    param([bool]$IsRunning)
    $btnStart.Enabled = -not $IsRunning
    $btnStop.Enabled = $IsRunning
}

function Build-CommandLine {
    $args = New-Object System.Collections.Generic.List[string]
    $args.Add("-NoProfile") | Out-Null
    $args.Add("-ExecutionPolicy") | Out-Null
    $args.Add("Bypass") | Out-Null
    $args.Add("-File") | Out-Null
    $args.Add((Escape-Arg $workerScript)) | Out-Null
    $args.Add("-InstallScriptUrl") | Out-Null
    $args.Add((Escape-Arg $txtUrl.Text.Trim())) | Out-Null

    if (-not [string]::IsNullOrWhiteSpace($txtIp.Text)) {
        $args.Add("-TargetIp") | Out-Null
        $args.Add((Escape-Arg $txtIp.Text.Trim())) | Out-Null
    }
    if (-not [string]::IsNullOrWhiteSpace($txtList.Text)) {
        $args.Add("-TargetListPath") | Out-Null
        $args.Add((Escape-Arg $txtList.Text.Trim())) | Out-Null
    }
    if (-not [string]::IsNullOrWhiteSpace($txtCidr.Text)) {
        $args.Add("-IpRangeCidr") | Out-Null
        $args.Add((Escape-Arg $txtCidr.Text.Trim())) | Out-Null
    }
    if (-not [string]::IsNullOrWhiteSpace($txtUser.Text)) {
        $args.Add("-Username") | Out-Null
        $args.Add((Escape-Arg $txtUser.Text.Trim())) | Out-Null
    }
    if (-not [string]::IsNullOrWhiteSpace($txtPass.Text)) {
        $args.Add("-Password") | Out-Null
        $args.Add((Escape-Arg $txtPass.Text)) | Out-Null
    }

    if ($chkSkipPorts.Checked) { $args.Add("-SkipPortChecks") | Out-Null }
    if ($chkWhatIf.Checked) { $args.Add("-WhatIf") | Out-Null }

    return ($args -join " ")
}

function Validate-Input {
    if ([string]::IsNullOrWhiteSpace($txtUrl.Text)) {
        throw "Install Script URL is required."
    }
    if ($txtUrl.Text -notmatch '([?&])expires=' -or $txtUrl.Text -notmatch '([?&])signature=') {
        throw "URL must be signed (include expires and signature)."
    }
    if ([string]::IsNullOrWhiteSpace($txtIp.Text) -and [string]::IsNullOrWhiteSpace($txtList.Text) -and [string]::IsNullOrWhiteSpace($txtCidr.Text)) {
        throw "Set at least one target: IP, list file, or CIDR range."
    }
    if ([string]::IsNullOrWhiteSpace($txtUser.Text) -or [string]::IsNullOrWhiteSpace($txtPass.Text)) {
        throw "Username and Password are required."
    }
}

function Show-SummaryPopup {
    $title = "DMS Remote Install Summary"
    $body = @"
Found targets: $script:totalTargets
Processed: $script:processedTargets
Installed: $script:okTargets
Failed: $script:failedTargets
Report: $script:reportPath
"@
    $icon = if ($script:failedTargets -gt 0) { [System.Windows.Forms.MessageBoxIcon]::Warning } else { [System.Windows.Forms.MessageBoxIcon]::Information }
    [System.Windows.Forms.MessageBox]::Show($body, $title, [System.Windows.Forms.MessageBoxButtons]::OK, $icon) | Out-Null
}

$form = New-Object System.Windows.Forms.Form
$form.Text = "DMS Remote Agent Installer (SMB/RPC)"
$form.Size = New-Object System.Drawing.Size(980, 700)
$form.StartPosition = "CenterScreen"
$form.BackColor = [System.Drawing.Color]::FromArgb(245, 248, 252)

$font = New-Object System.Drawing.Font("Segoe UI", 9)
$fontBold = New-Object System.Drawing.Font("Segoe UI", 9, [System.Drawing.FontStyle]::Bold)

$lblTitle = New-Object System.Windows.Forms.Label
$lblTitle.Text = "Standalone SMB/RPC Deployment"
$lblTitle.Font = New-Object System.Drawing.Font("Segoe UI", 12, [System.Drawing.FontStyle]::Bold)
$lblTitle.Location = New-Object System.Drawing.Point(16, 12)
$lblTitle.AutoSize = $true
$form.Controls.Add($lblTitle)

$panelTop = New-Object System.Windows.Forms.Panel
$panelTop.Location = New-Object System.Drawing.Point(16, 45)
$panelTop.Size = New-Object System.Drawing.Size(940, 220)
$panelTop.BorderStyle = "FixedSingle"
$panelTop.BackColor = [System.Drawing.Color]::White
$form.Controls.Add($panelTop)

$lblUrl = New-Object System.Windows.Forms.Label
$lblUrl.Text = "Install Script URL (signed):"
$lblUrl.Font = $fontBold
$lblUrl.Location = New-Object System.Drawing.Point(12, 12)
$lblUrl.AutoSize = $true
$panelTop.Controls.Add($lblUrl)

$txtUrl = New-Object System.Windows.Forms.TextBox
$txtUrl.Location = New-Object System.Drawing.Point(12, 32)
$txtUrl.Size = New-Object System.Drawing.Size(910, 23)
$txtUrl.Font = $font
$panelTop.Controls.Add($txtUrl)

$lblIp = New-Object System.Windows.Forms.Label
$lblIp.Text = "Single IP"
$lblIp.Location = New-Object System.Drawing.Point(12, 68)
$lblIp.AutoSize = $true
$panelTop.Controls.Add($lblIp)

$txtIp = New-Object System.Windows.Forms.TextBox
$txtIp.Location = New-Object System.Drawing.Point(12, 86)
$txtIp.Size = New-Object System.Drawing.Size(220, 23)
$txtIp.Font = $font
$panelTop.Controls.Add($txtIp)

$lblList = New-Object System.Windows.Forms.Label
$lblList.Text = "IP List File"
$lblList.Location = New-Object System.Drawing.Point(248, 68)
$lblList.AutoSize = $true
$panelTop.Controls.Add($lblList)

$txtList = New-Object System.Windows.Forms.TextBox
$txtList.Location = New-Object System.Drawing.Point(248, 86)
$txtList.Size = New-Object System.Drawing.Size(510, 23)
$txtList.Font = $font
$panelTop.Controls.Add($txtList)

$btnBrowse = New-Object System.Windows.Forms.Button
$btnBrowse.Text = "Browse"
$btnBrowse.Location = New-Object System.Drawing.Point(770, 84)
$btnBrowse.Size = New-Object System.Drawing.Size(72, 26)
$panelTop.Controls.Add($btnBrowse)

$lblCidr = New-Object System.Windows.Forms.Label
$lblCidr.Text = "CIDR Range"
$lblCidr.Location = New-Object System.Drawing.Point(12, 120)
$lblCidr.AutoSize = $true
$panelTop.Controls.Add($lblCidr)

$txtCidr = New-Object System.Windows.Forms.TextBox
$txtCidr.Location = New-Object System.Drawing.Point(12, 138)
$txtCidr.Size = New-Object System.Drawing.Size(220, 23)
$txtCidr.Font = $font
$panelTop.Controls.Add($txtCidr)

$lblUser = New-Object System.Windows.Forms.Label
$lblUser.Text = "Username"
$lblUser.Location = New-Object System.Drawing.Point(248, 120)
$lblUser.AutoSize = $true
$panelTop.Controls.Add($lblUser)

$txtUser = New-Object System.Windows.Forms.TextBox
$txtUser.Location = New-Object System.Drawing.Point(248, 138)
$txtUser.Size = New-Object System.Drawing.Size(250, 23)
$txtUser.Font = $font
$panelTop.Controls.Add($txtUser)

$lblPass = New-Object System.Windows.Forms.Label
$lblPass.Text = "Password"
$lblPass.Location = New-Object System.Drawing.Point(512, 120)
$lblPass.AutoSize = $true
$panelTop.Controls.Add($lblPass)

$txtPass = New-Object System.Windows.Forms.TextBox
$txtPass.Location = New-Object System.Drawing.Point(512, 138)
$txtPass.Size = New-Object System.Drawing.Size(246, 23)
$txtPass.Font = $font
$txtPass.UseSystemPasswordChar = $true
$panelTop.Controls.Add($txtPass)

$chkSkipPorts = New-Object System.Windows.Forms.CheckBox
$chkSkipPorts.Text = "Skip port checks (445/135)"
$chkSkipPorts.Location = New-Object System.Drawing.Point(12, 177)
$chkSkipPorts.AutoSize = $true
$panelTop.Controls.Add($chkSkipPorts)

$chkWhatIf = New-Object System.Windows.Forms.CheckBox
$chkWhatIf.Text = "WhatIf (dry run)"
$chkWhatIf.Location = New-Object System.Drawing.Point(210, 177)
$chkWhatIf.AutoSize = $true
$panelTop.Controls.Add($chkWhatIf)

$btnStart = New-Object System.Windows.Forms.Button
$btnStart.Text = "Start Install"
$btnStart.Location = New-Object System.Drawing.Point(770, 135)
$btnStart.Size = New-Object System.Drawing.Size(152, 30)
$btnStart.BackColor = [System.Drawing.Color]::FromArgb(14, 165, 233)
$btnStart.ForeColor = [System.Drawing.Color]::White
$btnStart.FlatStyle = "Flat"
$panelTop.Controls.Add($btnStart)

$btnStop = New-Object System.Windows.Forms.Button
$btnStop.Text = "Stop"
$btnStop.Location = New-Object System.Drawing.Point(770, 171)
$btnStop.Size = New-Object System.Drawing.Size(152, 28)
$btnStop.Enabled = $false
$panelTop.Controls.Add($btnStop)

$panelStats = New-Object System.Windows.Forms.Panel
$panelStats.Location = New-Object System.Drawing.Point(16, 275)
$panelStats.Size = New-Object System.Drawing.Size(940, 95)
$panelStats.BorderStyle = "FixedSingle"
$panelStats.BackColor = [System.Drawing.Color]::White
$form.Controls.Add($panelStats)

$lblTotal = New-Object System.Windows.Forms.Label
$lblTotal.Text = "Found: 0"
$lblTotal.Location = New-Object System.Drawing.Point(12, 10)
$lblTotal.AutoSize = $true
$lblTotal.Font = $fontBold
$panelStats.Controls.Add($lblTotal)

$lblProcessed = New-Object System.Windows.Forms.Label
$lblProcessed.Text = "Processed: 0"
$lblProcessed.Location = New-Object System.Drawing.Point(120, 10)
$lblProcessed.AutoSize = $true
$lblProcessed.Font = $fontBold
$panelStats.Controls.Add($lblProcessed)

$lblSuccess = New-Object System.Windows.Forms.Label
$lblSuccess.Text = "Installed: 0"
$lblSuccess.Location = New-Object System.Drawing.Point(245, 10)
$lblSuccess.AutoSize = $true
$lblSuccess.Font = $fontBold
$lblSuccess.ForeColor = [System.Drawing.Color]::FromArgb(22, 163, 74)
$panelStats.Controls.Add($lblSuccess)

$lblFailed = New-Object System.Windows.Forms.Label
$lblFailed.Text = "Failed: 0"
$lblFailed.Location = New-Object System.Drawing.Point(355, 10)
$lblFailed.AutoSize = $true
$lblFailed.Font = $fontBold
$lblFailed.ForeColor = [System.Drawing.Color]::FromArgb(220, 38, 38)
$panelStats.Controls.Add($lblFailed)

$lblCurrent = New-Object System.Windows.Forms.Label
$lblCurrent.Text = "Current: -"
$lblCurrent.Location = New-Object System.Drawing.Point(12, 38)
$lblCurrent.AutoSize = $true
$panelStats.Controls.Add($lblCurrent)

$lblReport = New-Object System.Windows.Forms.Label
$lblReport.Text = "Report: -"
$lblReport.Location = New-Object System.Drawing.Point(12, 58)
$lblReport.AutoSize = $true
$panelStats.Controls.Add($lblReport)

$progress = New-Object System.Windows.Forms.ProgressBar
$progress.Location = New-Object System.Drawing.Point(610, 35)
$progress.Size = New-Object System.Drawing.Size(310, 22)
$progress.Minimum = 0
$progress.Maximum = 1
$progress.Value = 0
$panelStats.Controls.Add($progress)

$txtLog = New-Object System.Windows.Forms.RichTextBox
$txtLog.Location = New-Object System.Drawing.Point(16, 380)
$txtLog.Size = New-Object System.Drawing.Size(940, 270)
$txtLog.ReadOnly = $true
$txtLog.Font = New-Object System.Drawing.Font("Consolas", 9)
$txtLog.BackColor = [System.Drawing.Color]::FromArgb(15, 23, 42)
$txtLog.ForeColor = [System.Drawing.Color]::FromArgb(226, 232, 240)
$form.Controls.Add($txtLog)

$btnBrowse.Add_Click({
    $dlg = New-Object System.Windows.Forms.OpenFileDialog
    $dlg.Filter = "Text files (*.txt)|*.txt|All files (*.*)|*.*"
    if ($dlg.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
        $txtList.Text = $dlg.FileName
    }
})

$btnStart.Add_Click({
    try {
        Validate-Input
        Reset-State
        $txtLog.Clear()
        Set-RunningState -IsRunning $true

        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = "powershell.exe"
        $psi.Arguments = Build-CommandLine
        $psi.UseShellExecute = $false
        $psi.RedirectStandardOutput = $true
        $psi.RedirectStandardError = $true
        $psi.CreateNoWindow = $true

        $script:proc = New-Object System.Diagnostics.Process
        $script:proc.StartInfo = $psi
        $script:proc.EnableRaisingEvents = $true

        $script:proc.add_OutputDataReceived({
            param($sender, $eventArgs)
            if ($eventArgs.Data) {
                Parse-OutputLine -Line $eventArgs.Data
            }
        })

        $script:proc.add_ErrorDataReceived({
            param($sender, $eventArgs)
            if ($eventArgs.Data) {
                Parse-OutputLine -Line ("[stderr] " + $eventArgs.Data)
            }
        })

        $script:proc.add_Exited({
            Invoke-Ui {
                Set-RunningState -IsRunning $false
                if ($script:reportPath -eq "") {
                    $lblReport.Text = "Report: (not generated)"
                }
                Show-SummaryPopup
            }
        })

        $started = $script:proc.Start()
        if (-not $started) {
            throw "Failed to start background process."
        }

        $script:proc.BeginOutputReadLine()
        $script:proc.BeginErrorReadLine()
        Append-Log -Line ("[info] Started: " + $psi.FileName + " " + $psi.Arguments)
    }
    catch {
        Set-RunningState -IsRunning $false
        [System.Windows.Forms.MessageBox]::Show(
            $_.Exception.Message,
            "Start Failed",
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    }
})

$btnStop.Add_Click({
    try {
        if ($null -ne $script:proc -and -not $script:proc.HasExited) {
            $script:proc.Kill()
            Append-Log -Line "[info] Process stopped by user."
        }
    }
    catch {
        Append-Log -Line ("[warn] Stop failed: " + $_.Exception.Message)
    }
})

$form.Add_FormClosing({
    if ($null -ne $script:proc -and -not $script:proc.HasExited) {
        try {
            $script:proc.Kill()
        }
        catch { }
    }
})

Update-Counters
[void]$form.ShowDialog()
