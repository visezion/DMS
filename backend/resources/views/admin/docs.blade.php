<x-admin-layout title="Docs" heading="Project Documentation">
    <style>
        .doc-shell {
            border-color: #d7deea;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        .doc-card {
            border: 1px solid #d7deea;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }
        .doc-kbd {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            border-radius: 0.4rem;
            padding: 0.1rem 0.35rem;
            font-size: 0.72rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
    </style>

    <section class="rounded-2xl border p-6 doc-shell bg-gradient-to-br from-slate-50 via-white to-slate-100">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">DMS Control Plane</p>
                <h3 class="text-2xl font-semibold text-slate-900 mt-1">Operations, Security, Deployment, and API Guide</h3>
                <p class="text-sm text-slate-600 mt-2">
                    This page is the operator handbook for daily administration, incident response, and rollout tasks.
                    Source documents live in <code>docs/</code> and are embedded below for direct access.
                </p>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 text-xs min-w-[260px]">
                <a href="#quick-start" class="rounded-lg border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50">Quick Start</a>
                <a href="#admin-functions" class="rounded-lg border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50">Admin Functions</a>
                <a href="#agent-lifecycle" class="rounded-lg border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50">Agent Lifecycle</a>
                <a href="#api-reference" class="rounded-lg border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50">API Reference</a>
                <a href="#security-ops" class="rounded-lg border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50">Security & Ops</a>
                <a href="#troubleshooting" class="rounded-lg border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50">Troubleshooting</a>
                <a href="#runbooks" class="rounded-lg border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50">Runbooks</a>
                <a href="#sources" class="rounded-lg border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50">Source Files</a>
            </div>
        </div>
    </section>

    <section id="quick-start" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-4 doc-shell">
        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-lg text-slate-900">Quick Start</h3>
            <span class="doc-kbd">/admin</span>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="doc-card rounded-xl p-4">
                <p class="font-medium text-sm mb-2">Backend (Laravel)</p>
<pre class="text-xs font-mono whitespace-pre-wrap">cd backend
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve</pre>
            </div>
            <div class="doc-card rounded-xl p-4">
                <p class="font-medium text-sm mb-2">Agent (.NET 8)</p>
<pre class="text-xs font-mono whitespace-pre-wrap">cd agent
dotnet restore .\src\Dms.Agent.Service\Dms.Agent.Service.csproj
dotnet publish .\src\Dms.Agent.Service\Dms.Agent.Service.csproj -c Release -r win-x64 -p:PublishSingleFile=true -o .\dist\manual-test</pre>
            </div>
        </div>
        <div class="doc-card rounded-xl p-4 text-sm text-slate-700">
            Log in at <code>/admin</code>, open <strong>Enroll Devices</strong> for onboarding, then use <strong>Agent Delivery</strong> for release lifecycle.
        </div>
    </section>

    <section id="admin-functions" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-4 doc-shell">
        <h3 class="font-semibold text-lg text-slate-900">Admin Function Map</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b text-slate-500">
                        <th class="py-2">Page</th>
                        <th class="py-2">Purpose</th>
                        <th class="py-2">Key Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Enroll Devices</td><td class="py-2">Primary enrollment workflow page</td><td class="py-2">Generate token, view steps, onboard Windows devices</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Overview</td><td class="py-2">Fleet health dashboard</td><td class="py-2">KPI monitoring, security status, key rotation, recent activity</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Devices</td><td class="py-2">Device management and live diagnostics</td><td class="py-2">Search, status tracking, re-enroll, detailed runtime data</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Groups</td><td class="py-2">Targeting and segmentation</td><td class="py-2">Create groups, assign members for rollout scope</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Application Management</td><td class="py-2">Software package lifecycle</td><td class="py-2">Create versions, assign/uninstall by target scope</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Policy Center</td><td class="py-2">Policy authoring and governance</td><td class="py-2">Policies, catalog presets, categories, apply/remove mode versioning</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Jobs</td><td class="py-2">Remote execution workflow</td><td class="py-2">Queue install/uninstall/policy jobs and track outcomes</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Deployment Center</td><td class="py-2">Delivery tooling</td><td class="py-2">Agent Delivery and IP Deployment workflows</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Settings</td><td class="py-2">System-wide operational controls</td><td class="py-2">Kill switch, retries, backoff, allowlist, signature bypass, enrollment token</td></tr>
                    <tr class="align-top"><td class="py-2 font-medium">Audit Logs</td><td class="py-2">Forensic trail</td><td class="py-2">Review immutable admin/device action history</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section id="agent-lifecycle" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-4 doc-shell">
        <h3 class="font-semibold text-lg text-slate-900">Agent Lifecycle</h3>
        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4 text-sm">
            <div class="doc-card rounded-xl p-4"><p class="font-medium">1) Enroll</p><p class="text-slate-600 mt-1">Generate token and run installer from Windows PowerShell as Administrator.</p></div>
            <div class="doc-card rounded-xl p-4"><p class="font-medium">2) Check-in</p><p class="text-slate-600 mt-1">Agent polls and receives signed commands with replay protection.</p></div>
            <div class="doc-card rounded-xl p-4"><p class="font-medium">3) Execute</p><p class="text-slate-600 mt-1">Handlers run package/policy actions under safety controls.</p></div>
            <div class="doc-card rounded-xl p-4"><p class="font-medium">4) Report</p><p class="text-slate-600 mt-1">Job results and compliance states are reported to backend.</p></div>
        </div>
        <p class="text-xs text-slate-600">Service path: <code>C:\Program Files\DMS Agent\Dms.Agent.Service.exe</code></p>
    </section>

    <section id="api-reference" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-4 doc-shell">
        <h3 class="font-semibold text-lg text-slate-900">API Reference (Core)</h3>
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="doc-card rounded-xl p-4">
                <p class="text-xs uppercase text-slate-500">Device APIs</p>
<pre class="text-xs font-mono whitespace-pre-wrap mt-2">POST /api/v1/device/enroll
POST /api/v1/device/heartbeat
POST /api/v1/device/checkin
GET  /api/v1/device/keyset
GET  /api/v1/device/policies
POST /api/v1/device/job-ack
POST /api/v1/device/job-result
POST /api/v1/device/compliance-report
GET  /api/v1/device/packages/{id}/download-meta</pre>
            </div>
            <div class="doc-card rounded-xl p-4">
                <p class="text-xs uppercase text-slate-500">Admin APIs (Sanctum)</p>
<pre class="text-xs font-mono whitespace-pre-wrap mt-2">POST /api/v1/auth/login
GET  /api/v1/auth/me
POST /api/v1/auth/logout
GET/PATCH /api/v1/admin/devices
GET/POST  /api/v1/admin/groups
GET/POST  /api/v1/admin/packages
GET/POST  /api/v1/admin/policies
GET/POST  /api/v1/admin/jobs
GET       /api/v1/admin/audit-logs</pre>
            </div>
        </div>
    </section>

    <section id="security-ops" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-4 doc-shell">
        <h3 class="font-semibold text-lg text-slate-900">Security and Operations</h3>
        <div class="grid gap-4 lg:grid-cols-3 text-sm">
            <div class="doc-card rounded-xl p-4">
                <p class="font-medium">Command Integrity</p>
                <p class="text-slate-600 mt-1">Ed25519-signed envelopes plus nonce and sequence replay protection.</p>
            </div>
            <div class="doc-card rounded-xl p-4">
                <p class="font-medium">Safe Execution</p>
                <p class="text-slate-600 mt-1"><code>run_command</code> is restricted and script hash allowlist is enforced.</p>
            </div>
            <div class="doc-card rounded-xl p-4">
                <p class="font-medium">Kill Switch</p>
                <p class="text-slate-600 mt-1">Pause dispatch globally from <code>Settings</code> -> <code>Operations Controls</code>.</p>
            </div>
        </div>
    </section>

    <section id="troubleshooting" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-3 doc-shell">
        <h3 class="font-semibold text-lg text-slate-900">Troubleshooting</h3>
        <div class="grid gap-3">
            <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm">If agent install says file not found, rebuild release so updated installer scripts are included.</div>
            <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm">If enrollment token is missing/invalid, regenerate token and rerun script as Administrator.</div>
            <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm">If installer URL fails from client, use LAN IP or DNS host reachable from target machine.</div>
            <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm">If auto-build fails with disk errors, free space or reduce publish footprint.</div>
        </div>
    </section>

    <section id="runbooks" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-3 doc-shell">
        <h3 class="font-semibold text-lg text-slate-900">Embedded Runbooks</h3>
        <details class="rounded-lg border border-slate-200 p-3 bg-slate-50" open>
            <summary class="font-medium cursor-pointer">Operations Runbook</summary>
            <pre class="mt-3 text-xs whitespace-pre-wrap font-mono">{{ $operationsRunbook }}</pre>
        </details>
        <details class="rounded-lg border border-slate-200 p-3 bg-slate-50">
            <summary class="font-medium cursor-pointer">Architecture</summary>
            <pre class="mt-3 text-xs whitespace-pre-wrap font-mono">{{ $architectureDoc }}</pre>
        </details>
        <details class="rounded-lg border border-slate-200 p-3 bg-slate-50">
            <summary class="font-medium cursor-pointer">Functions Guide (Raw)</summary>
            <pre class="mt-3 text-xs whitespace-pre-wrap font-mono">{{ $functionsGuide }}</pre>
        </details>
        <details class="rounded-lg border border-slate-200 p-3 bg-slate-50">
            <summary class="font-medium cursor-pointer">Docs Maintenance Policy</summary>
            <pre class="mt-3 text-xs whitespace-pre-wrap font-mono">{{ $docsPolicy }}</pre>
        </details>
    </section>

    <section id="sources" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-2 doc-shell">
        <h3 class="font-semibold text-lg text-slate-900">Documentation Source Files</h3>
        <p class="text-sm text-slate-600">Update these files whenever features or workflows change:</p>
<pre class="text-xs font-mono whitespace-pre-wrap bg-slate-50 border border-slate-200 rounded-lg p-3">docs/FUNCTIONS_GUIDE.md
docs/runbooks/operations.md
docs/architecture/architecture.md
docs/DOCS_MAINTENANCE_POLICY.md</pre>
    </section>
</x-admin-layout>
