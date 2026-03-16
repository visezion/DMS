<x-admin-layout title="Docs" heading="Project Documentation">
    @php
        $featureGuides = [
            [
                'title' => 'Overview Dashboard',
                'summary' => 'Use the dashboard as the first stop for fleet health, rollout risk, and security posture.',
                'paths' => ['/admin'],
                'works' => [
                    'The dashboard aggregates device state, job outcomes, security posture, and operational controls into one admin landing page.',
                    'It is meant to answer what changed, what is broken, and where you should drill in next.',
                    'The value of the dashboard is navigation: it should send you to Devices, Jobs, Policies, or Security Hardening to take action.',
                ],
                'example_title' => 'Example: investigate a bad rollout',
                'example_steps' => [
                    'You notice failed jobs or a lower security score after a change window.',
                    'Open the affected metric from Overview, inspect the impacted devices, then move into Jobs or Policies to rerun, remove, or correct the rollout.',
                ],
            ],
            [
                'title' => 'Enroll Devices',
                'summary' => 'Bring a new Windows endpoint under management and make it start checking in.',
                'paths' => ['/admin/enroll-devices', '/admin/devices'],
                'works' => [
                    'Admins generate or reuse an enrollment token, then run the installer script on the Windows endpoint as Administrator.',
                    'The agent enrolls, stores identity locally, starts the Windows service, and begins heartbeat and check-in traffic.',
                    'After enrollment, the device appears in Devices and becomes available for group membership, policy assignment, package deployment, and jobs.',
                ],
                'example_title' => 'Example: onboard a new lab PC',
                'example_steps' => [
                    'Open Enroll Devices, copy the installer command, and run it on LAB-PC-22.',
                    'Confirm the device appears in Devices, then add it to the Student Lab group so it inherits the correct restrictions and packages.',
                ],
            ],
            [
                'title' => 'Devices',
                'summary' => 'Manage one endpoint directly when you need diagnostics, lifecycle actions, or emergency targeting.',
                'paths' => ['/admin/devices', '/admin/devices/{deviceId}', '/admin/devices/{deviceId}/live'],
                'works' => [
                    'The Devices list is the operational inventory for hostnames, status, agent version, and last check-in.',
                    'Device Detail shows deeper data such as inventory, network information, assignments, and queued actions.',
                    'From a device you can re-enroll, reboot, uninstall the agent, remove assignments, or queue one-off actions when group targeting is too broad.',
                ],
                'example_title' => 'Example: fix one offline laptop',
                'example_steps' => [
                    'Search for a staff laptop in Devices and open Device Detail.',
                    'Use the live view to confirm recent check-in and inventory, then queue a reboot or remove a bad assignment if that single device is broken.',
                ],
            ],
            [
                'title' => 'Groups',
                'summary' => 'Groups are the main targeting container for members, policies, packages, and bundled lockdown workflows.',
                'paths' => ['/admin/groups', '/admin/groups/{groupId}'],
                'works' => [
                    'A group collects devices so you can assign policy versions and package deployments once instead of device by device.',
                    'Group Detail lets admins search and add members, then attach policies and packages from searchable pickers.',
                    'The kiosk lockdown bundle composes multiple existing policies into one controlled rollout for labs or kiosks.',
                ],
                'example_title' => 'Example: build a student lab group',
                'example_steps' => [
                    'Create a group named Student Lab - Floor 2, then search and add the classroom devices as members.',
                    'Apply the kiosk lockdown bundle and add required packages so every current and future member receives the same baseline.',
                ],
            ],
            [
                'title' => 'Software Packages',
                'summary' => 'Packages are the software catalog for install, uninstall, versioning, and rollout history.',
                'paths' => ['/admin/packages', '/admin/packages/{packageId}'],
                'works' => [
                    'Create a package shell first, then add versions with artifacts or source URIs, hashes, detection rules, and install arguments.',
                    'Each version can be deployed to a device, a group, or all devices through the package detail view.',
                    'Deployment results are recorded through jobs and deployment history so admins can see what shipped and what failed.',
                ],
                'example_title' => 'Example: deploy Notepad++',
                'example_steps' => [
                    'Create a Notepad++ package, add version 8.9.2 with detection data, then open that version in Package Detail.',
                    'Deploy the version to the Accounting group and monitor completion in Deployment History and Jobs.',
                ],
            ],
            [
                'title' => 'Policies',
                'summary' => 'Policies are versioned configuration rules that can be assigned safely and removed cleanly.',
                'paths' => ['/admin/policies', '/admin/policies/{policyId}', '/admin/catalog'],
                'works' => [
                    'A policy contains one or more typed rules such as registry, firewall, local_group, command, DNS, or network_adapter.',
                    'Admins assign a specific policy version to a device or group so changes are explicit and auditable.',
                    'When a policy is removed, the platform can queue cleanup logic so Windows returns to its default or previous state where supported.',
                ],
                'example_title' => 'Example: restrict a student lab safely',
                'example_steps' => [
                    'Assign Disable Control Panel and Student Lab - Local Admin Restriction to the Student Lab group.',
                    'If the group no longer needs those controls, remove the assignments and the cleanup logic restores the Control Panel default and previous local admin state.',
                ],
            ],
            [
                'title' => 'Network Policies',
                'summary' => 'Network policy rules let admins manage DNS and IPv4 adapter state without raw command payloads.',
                'paths' => ['/admin/policies/{policyId}'],
                'works' => [
                    'DNS rules support automatic or manual server configuration and can target adapters by alias, index, or description.',
                    'Network adapter rules support DHCP or static IPv4 settings, including subnet mask or gateway data through the guided editor.',
                    'Removal behavior is important: DNS returns to automatic and IPv4 returns to DHCP when the policy cleanup runs.',
                ],
                'example_title' => 'Example: set branch office DNS',
                'example_steps' => [
                    'Create a DNS policy that targets the Ethernet adapter and sets preferred and alternate DNS servers for the branch office.',
                    'Assign it to the branch group, then remove it later to send those devices back to automatic DNS.',
                ],
            ],
            [
                'title' => 'Jobs',
                'summary' => 'Jobs are the execution layer for packages, policies, reboots, commands, updates, and rollback operations.',
                'paths' => ['/admin/jobs', '/admin/jobs/{jobId}'],
                'works' => [
                    'Every remote operation eventually becomes a job and one or more device runs with statuses such as pending, running, acked, completed, or failed.',
                    'The Jobs page lets admins queue a new job manually, filter the current queue, rerun items, and inspect payload history.',
                    'Jobs are the main place to confirm whether a rollout actually executed on endpoints, not just whether it was assigned in the UI.',
                ],
                'example_title' => 'Example: test a command on one endpoint',
                'example_steps' => [
                    'Queue a run_command job with a simple payload such as hostname against a single device.',
                    'Watch the status move through pending to completed, then open the job detail to verify the output and timing.',
                ],
            ],
            [
                'title' => 'Agent Delivery and IP Deployment',
                'summary' => 'These tools manage the agent release lifecycle and remote installation workflows.',
                'paths' => ['/admin/agent', '/admin/ip-deploy'],
                'works' => [
                    'Agent Delivery handles release upload or autobuild, release activation, installer generation, push updates, and connectivity checks.',
                    'IP Deployment is for remote installation workflows when you know the target hosts and need to push the agent over network protocols.',
                    'Together they cover both packaging the agent and distributing it into the fleet.',
                ],
                'example_title' => 'Example: ship a new agent release',
                'example_steps' => [
                    'Build or upload agent release 1.3.0, activate it, and generate a fresh installer for new devices.',
                    'After a pilot verifies clean check-ins, use push update so enrolled devices move to the new release.',
                ],
            ],
            [
                'title' => 'AI Control Center',
                'summary' => 'Behavior AI collects telemetry, produces anomaly cases, and lets admins convert signals into actions.',
                'paths' => ['/admin/behavior-ai', '/admin/behavior-alerts'],
                'works' => [
                    'Endpoints send behavior events such as logon activity, app launches, and file access into the backend.',
                    'The AI and rule engines create cases and recommendations, which admins can review, approve, dismiss, replay, or retrain against.',
                    'Runtime controls on the page help operators keep the queue worker and scheduler healthy so detection stays active.',
                ],
                'example_title' => 'Example: review suspicious behavior',
                'example_steps' => [
                    'An endpoint starts launching blocked tools outside normal hours and a case appears in AI Control Center.',
                    'Review the recommendation, approve the policy action if it is valid, or dismiss it if the activity is expected for that user.',
                ],
            ],
            [
                'title' => 'Behavioral Baseline Center',
                'summary' => 'Per-device baseline modeling that learns normal behavior and flags drift with explainable categories.',
                'paths' => ['/admin/behavior-baseline'],
                'works' => [
                    'Baseline profiles are built from behavior logs per device and become active after minimum sample thresholds are reached.',
                    'Each new event is compared against that device profile for rare process, off-pattern login time, unusual network usage, abnormal CPU or memory usage, and new application usage.',
                    'When drift score exceeds threshold, a drift record is stored and contributes to fleet-level risk visibility and response planning.',
                ],
                'example_title' => 'Example: warm up baseline for student lab devices',
                'example_steps' => [
                    'Open Behavioral Baseline, enable baseline modeling, and queue baseline backfill for the last 30 days.',
                    'After profiles become ready, review drift feed entries and investigate high-severity records with unusual process or network patterns.',
                ],
            ],
            [
                'title' => 'Settings and Security Hardening',
                'summary' => 'Settings control global safety rails, trust boundaries, branding, and authentication posture.',
                'paths' => ['/admin/settings', '/admin/security-hardening', '/admin/settings/branding'],
                'works' => [
                    'Operations controls include kill switch, retry counts, backoff, behavior detection mode, and script hash allowlist controls.',
                    'Security settings cover signature bypass rules, auth hardening, HTTPS app URL, and other trust-sensitive options.',
                    'These are high-impact controls because they affect the whole platform, not just one device or group.',
                ],
                'example_title' => 'Example: freeze dispatch during maintenance',
                'example_steps' => [
                    'Before backend maintenance, enable the kill switch in Settings so no new dispatches are sent.',
                    'Complete the maintenance, verify health, then disable the kill switch so normal delivery resumes.',
                ],
            ],
            [
                'title' => 'Access Control, Audit Logs, and Notes',
                'summary' => 'Use these modules to control who can act, trace what happened, and keep shared operator notes.',
                'paths' => ['/admin/access', '/admin/audit', '/admin/notes'],
                'works' => [
                    'Access Control creates staff users, roles, and permission sets so teams only see or change the modules they own.',
                    'Audit Logs record important admin and device-side actions for forensics, compliance, and rollback analysis.',
                    'Admin Notes provide a lightweight internal knowledge base for change windows, exceptions, and operator reminders.',
                ],
                'example_title' => 'Example: delegate helpdesk access safely',
                'example_steps' => [
                    'Create a Helpdesk role with read access to Devices and Jobs but without policy or package write permissions.',
                    'When a change is made, use Audit Logs to see who performed it and pin a note with the agreed support procedure.',
                ],
            ],
            [
                'title' => 'API and Agent Flow',
                'summary' => 'The API and Windows agent form the execution pipeline behind every UI action.',
                'paths' => ['/api/v1/device/*', '/api/v1/admin/*'],
                'works' => [
                    'A device enrolls once, then repeatedly heartbeats, checks in for work, acknowledges job receipt, executes locally, and reports results back.',
                    'Admin APIs expose the same core entities that the web UI uses: devices, groups, packages, policies, jobs, and audit logs.',
                    'Understanding this flow matters when debugging because a UI issue may actually be a check-in, signing, or job-result problem.',
                ],
                'example_title' => 'Example: package deployment end-to-end',
                'example_steps' => [
                    'An admin deploys a package version to a group from the web UI.',
                    'The backend creates jobs, each device receives the command at check-in, downloads metadata, executes the install, and posts ACK plus result back to the API.',
                ],
            ],
        ];
    @endphp
<section class="rounded-2xl border p-5 doc-shell bg-gradient-to-br from-slate-50 via-white to-slate-100">
        <div class="space-y-4">
            <div class="max-w-3xl">
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">DMS Control Plane</p>
                <h3 class="mt-1 text-xl font-semibold text-slate-900 md:text-2xl">Operations, Security, Deployment, and API Guide</h3>
                <p class="mt-1.5 text-sm text-slate-600">
                    This page is the operator handbook for daily administration, incident response, and rollout tasks.
                    It now includes a practical feature guide so admins can see how each major module works and what a normal example looks like.
                </p>
            </div>
            <div class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <a href="#quick-start" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Quick Start</a>
                <a href="#feature-playbooks" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Feature Guide</a>
                <a href="#behavior-baseline-guide" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Behavior Baseline</a>
                <a href="#admin-functions" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Admin Functions</a>
                <a href="#agent-lifecycle" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Agent Lifecycle</a>
                <a href="#api-reference" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">API Reference</a>
                <a href="#security-ops" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Security & Ops</a>
                <a href="#troubleshooting" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Troubleshooting</a>
                <a href="#runbooks" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Runbooks</a>
                <a href="#sources" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-center hover:bg-slate-50">Source Files</a>
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

    <section id="feature-playbooks" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-4 doc-shell">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-lg text-slate-900">How Each Feature Works</h3>
                <p class="text-sm text-slate-600 mt-1">Use this section when you need to understand the job of a module, how the flow behaves, and what a real admin example looks like.</p>
            </div>
            <span class="doc-kbd">Examples Included</span>
        </div>

        <div class="space-y-3">
            @foreach($featureGuides as $guide)
                <details class="doc-feature-card rounded-xl p-4" @if($loop->first) open @endif>
                    <summary class="cursor-pointer list-none">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900">{{ $guide['title'] }}</p>
                                <p class="mt-1 text-sm text-slate-600">{{ $guide['summary'] }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-[11px]">
                                @foreach($guide['paths'] as $path)
                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-mono text-slate-600">{{ $path }}</span>
                                @endforeach
                            </div>
                        </div>
                    </summary>

                    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.6fr),minmax(260px,1fr)]">
                        <div>
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">How It Works</p>
                            <div class="mt-2 space-y-2">
                                @foreach($guide['works'] as $item)
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $item }}</div>
                                @endforeach
                            </div>
                        </div>

                        <div class="doc-example-box rounded-xl p-4">
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Example</p>
                            <p class="mt-2 text-sm font-semibold text-slate-900">{{ $guide['example_title'] }}</p>
                            <div class="mt-2 space-y-2">
                                @foreach($guide['example_steps'] as $step)
                                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">{{ $step }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
    </section>

    <section id="behavior-baseline-guide" class="rounded-2xl bg-white border border-slate-200 p-5 space-y-4 doc-shell">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-lg text-slate-900">Behavioral Baseline: Full Meaning and Examples</h3>
                <p class="text-sm text-slate-600 mt-1">
                    This section explains every field in <code>/admin/behavior-baseline</code>, what it means operationally,
                    and what a normal example looks like.
                </p>
            </div>
            <div class="flex flex-wrap gap-2 text-[11px]">
                <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-mono text-slate-600">/admin/behavior-baseline</span>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-mono text-slate-600">behavior.baseline.* settings</span>
            </div>
        </div>

        <div class="doc-card rounded-xl p-4 text-sm text-slate-700 space-y-2">
            <p class="font-medium text-slate-900">What the module does</p>
            <p>
                The platform learns normal behavior per device from historical behavior logs, then scores each new event against that baseline.
                When drift exceeds threshold, it records a baseline drift event and contributes signal to risk workflows.
            </p>
            <p class="text-xs text-slate-600">
                Pipeline: <code>device_behavior_logs</code> -> baseline profile learning -> drift score -> drift event -> risk visibility.
            </p>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="doc-card rounded-xl p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Baseline Settings</p>
                <div class="overflow-x-auto mt-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left border-b text-slate-500">
                                <th class="py-2">Field</th>
                                <th class="py-2">Meaning</th>
                                <th class="py-2">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b align-top"><td class="py-2 font-mono text-xs">baseline_enabled</td><td class="py-2">Turns baseline modeling on or off.</td><td class="py-2">Enabled for production learning.</td></tr>
                            <tr class="border-b align-top"><td class="py-2 font-mono text-xs">min_samples</td><td class="py-2">Minimum events required before a profile is considered mature.</td><td class="py-2">30 means first 29 events are warm-up only.</td></tr>
                            <tr class="border-b align-top"><td class="py-2 font-mono text-xs">min_login_samples</td><td class="py-2">Minimum login events needed to evaluate abnormal login-time drift.</td><td class="py-2">12 login samples before hourly pattern checks activate.</td></tr>
                            <tr class="border-b align-top"><td class="py-2 font-mono text-xs">min_numeric_samples</td><td class="py-2">Minimum numeric samples for network and CPU/memory drift scoring.</td><td class="py-2">20 samples before z-score style numeric checks.</td></tr>
                            <tr class="border-b align-top"><td class="py-2 font-mono text-xs">max_category_bins</td><td class="py-2">Caps the stored categorical counters to control profile size.</td><td class="py-2">240 keeps top process/app bins only.</td></tr>
                            <tr class="border-b align-top"><td class="py-2 font-mono text-xs">category_drift_threshold</td><td class="py-2">Per-category cutoff used to mark drift categories as triggered.</td><td class="py-2">0.70 marks <code>rare_process</code> when score >= 0.70.</td></tr>
                            <tr class="align-top"><td class="py-2 font-mono text-xs">drift_event_threshold</td><td class="py-2">Minimum final baseline drift score to persist a drift event row.</td><td class="py-2">0.68 means lower scores are not stored as drift events.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="doc-card rounded-xl p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Top Card Metrics (Behavioral Baseline page)</p>
                <div class="overflow-x-auto mt-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left border-b text-slate-500">
                                <th class="py-2">Metric</th>
                                <th class="py-2">Meaning</th>
                                <th class="py-2">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b align-top"><td class="py-2">Profiles</td><td class="py-2">Total count of device baseline profiles.</td><td class="py-2">120 profiles for 120 enrolled active devices.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Ready</td><td class="py-2">Profiles that reached <code>min_samples</code>.</td><td class="py-2">95 ready, 25 still warming.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Coverage</td><td class="py-2">Ready / total profile percentage.</td><td class="py-2">79.2% means baseline is mostly active fleet-wide.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Drift Events (24h)</td><td class="py-2">Count of stored drift events in the last day.</td><td class="py-2">14 drift events in the last 24 hours.</td></tr>
                            <tr class="align-top"><td class="py-2">Drift Events (7d)</td><td class="py-2">Count of drift events in the last seven days.</td><td class="py-2">63 events in weekly window.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="doc-card rounded-xl p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Behavioral Drift Feed: Field Meaning</p>
                <div class="overflow-x-auto mt-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left border-b text-slate-500">
                                <th class="py-2">Field</th>
                                <th class="py-2">Meaning</th>
                                <th class="py-2">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b align-top"><td class="py-2">Device</td><td class="py-2">Resolved hostname plus device UUID.</td><td class="py-2"><code>LAB-PC-22 | 9f...-uuid</code></td></tr>
                            <tr class="border-b align-top"><td class="py-2">Detected</td><td class="py-2">When drift was observed (<code>detected_at</code>).</td><td class="py-2">Detected 2 minutes ago.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Severity</td><td class="py-2">Derived from drift score bands.</td><td class="py-2"><code>high</code> when score >= 0.86.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Score</td><td class="py-2">Final baseline drift score (0..1).</td><td class="py-2">0.9132 indicates strong deviation.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Behavior log</td><td class="py-2">Source event id that triggered this drift entry.</td><td class="py-2">UUID used to trace original event payload.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Anomaly case</td><td class="py-2">Linked AI case id if one exists for the same event.</td><td class="py-2">UUID when AI case was opened; otherwise n/a.</td></tr>
                            <tr class="align-top"><td class="py-2">Categories</td><td class="py-2">Drift dimensions that crossed category threshold.</td><td class="py-2"><code>rare_process, unusual_network_usage</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="doc-card rounded-xl p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Device Baseline Profiles: Field Meaning</p>
                <div class="overflow-x-auto mt-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left border-b text-slate-500">
                                <th class="py-2">Field</th>
                                <th class="py-2">Meaning</th>
                                <th class="py-2">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b align-top"><td class="py-2">Samples</td><td class="py-2">Current sample count stored for that device baseline.</td><td class="py-2">Sample count 146 means mature profile.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">State (ready/warming)</td><td class="py-2">Whether <code>sample_count</code> reached <code>min_samples</code>.</td><td class="py-2">Warming at 18/30 samples.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Last event</td><td class="py-2">Timestamp of most recent event that updated profile.</td><td class="py-2">Last event 34 seconds ago for active device.</td></tr>
                            <tr class="align-top"><td class="py-2">Updated</td><td class="py-2">Last model update time for that profile row.</td><td class="py-2">Updated 34 seconds ago after ingest.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="doc-card rounded-xl p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Baseline Bootstrap (Backfill) Fields</p>
                <div class="overflow-x-auto mt-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left border-b text-slate-500">
                                <th class="py-2">Field</th>
                                <th class="py-2">Meaning</th>
                                <th class="py-2">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b align-top"><td class="py-2">Days</td><td class="py-2">How far back to read behavior logs during backfill.</td><td class="py-2">30 days for normal rollout; 90 for slow fleets.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Max events</td><td class="py-2">Upper cap on processed rows in one backfill run.</td><td class="py-2">5000 for safe bootstrap, 20000 for larger tenants.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Auto-enable</td><td class="py-2">Turns baseline feature on before queueing backfill.</td><td class="py-2">Enable + Queue in one click for first activation.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Last requested</td><td class="py-2">When backfill was queued.</td><td class="py-2">Queued 1 minute ago.</td></tr>
                            <tr class="border-b align-top"><td class="py-2">Last completed</td><td class="py-2">When last backfill finished.</td><td class="py-2">Completed 40 seconds ago.</td></tr>
                            <tr class="align-top"><td class="py-2">Processed / Failed</td><td class="py-2">Backfill outcome counters for event processing.</td><td class="py-2">Processed 5000, failed 3.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="doc-card rounded-xl p-4">
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Drift Categories and Meanings</p>
                <div class="mt-2 space-y-2 text-sm">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"><span class="font-mono text-xs">rare_process</span> - process is uncommon or unseen on that device baseline. Example: <code>mimikatz.exe</code> first seen.</div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"><span class="font-mono text-xs">abnormal_login_time</span> - login occurs at rare hour for that device/user pattern. Example: 03:42 logon on classroom PC.</div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"><span class="font-mono text-xs">unusual_network_usage</span> - network bytes or connection shape deviates strongly from baseline. Example: outbound spike to 9.5MB where baseline is 60KB.</div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"><span class="font-mono text-xs">abnormal_cpu_memory</span> - CPU or memory metrics are far outside learned operating envelope. Example: CPU jumps from typical 12% to 92%.</div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"><span class="font-mono text-xs">new_application</span> - app launch not present in baseline app history. Example: new unsigned utility appears on exam lab endpoint.</div>
                </div>
            </div>
        </div>

        <div class="doc-example-box rounded-xl p-4">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">End-to-End Example</p>
            <p class="mt-2 text-sm font-semibold text-slate-900">Student Lab baseline rollout and drift response</p>
            <div class="mt-2 grid gap-2">
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">1. Enable baseline modeling and queue a 30-day backfill for 5000 events.</div>
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">2. Wait until profile coverage is mostly ready (for example above 80%).</div>
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">3. Review new high-severity drift feed entries and confirm categories match suspicious activity.</div>
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">4. Open linked anomaly case and decide policy or remediation action based on evidence.</div>
            </div>
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
                    <tr class="border-b align-top"><td class="py-2 font-medium">AI Control Center</td><td class="py-2">Behavior analytics and recommendations</td><td class="py-2">Review anomaly cases, replay streams, train or retrain models, approve recommendations</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Deployment Center</td><td class="py-2">Delivery tooling</td><td class="py-2">Agent Delivery and IP Deployment workflows</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Settings</td><td class="py-2">System-wide operational controls</td><td class="py-2">Kill switch, retries, backoff, allowlist, signature bypass, enrollment token</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Access Control</td><td class="py-2">Role and permission management</td><td class="py-2">Create users, assign roles, restrict module access</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Notes</td><td class="py-2">Internal operator knowledge base</td><td class="py-2">Store pinned runbooks, change notes, and support reminders</td></tr>
                    <tr class="align-top"><td class="py-2 font-medium">Audit Logs</td><td class="py-2">Forensic trail</td><td class="py-2">Review immutable admin and device action history</td></tr>
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
        <div class="doc-card rounded-xl p-4 text-sm text-slate-700">
            <p class="font-medium text-slate-900">Example API flow</p>
            <p class="mt-2">A new endpoint enrolls through <code>POST /api/v1/device/enroll</code>, checks in through <code>POST /api/v1/device/checkin</code>, acknowledges work through <code>POST /api/v1/device/job-ack</code>, then reports the final result through <code>POST /api/v1/device/job-result</code>.</p>
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
