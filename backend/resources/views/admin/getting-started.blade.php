<x-admin-layout title="Getting Started" heading="Getting Started">
    <section class="rounded-2xl border border-skyline/30 bg-skyline/10 p-5">
        <p class="text-xs uppercase tracking-wide text-slate-600">DMS Control Plane</p>
        <h3 class="mt-1 text-xl font-semibold">What, Where, and How to Use the System</h3>
        <p class="mt-2 text-sm text-slate-700">
            This page is the quick onboarding guide for admins. Use it to understand what each module does,
            where to find it, and the normal daily workflow.
        </p>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-3">
        <h3 class="text-lg font-semibold">1. What This System Does</h3>
        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3 text-sm">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="font-medium">Device Management</p>
                <p class="mt-1 text-slate-600">Enroll Windows devices and track online/offline status.</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="font-medium">Policy Enforcement</p>
                <p class="mt-1 text-slate-600">Apply and remove security/device-control policies safely.</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="font-medium">Software Delivery</p>
                <p class="mt-1 text-slate-600">Deploy or uninstall software packages to devices/groups.</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="font-medium">Job Execution</p>
                <p class="mt-1 text-slate-600">Queue operations and monitor execution results.</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="font-medium">Agent Deployment</p>
                <p class="mt-1 text-slate-600">Build/upload releases and distribute installer scripts.</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p class="font-medium">Audit and Access</p>
                <p class="mt-1 text-slate-600">Control admin permissions and track all major actions.</p>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-3">
        <h3 class="text-lg font-semibold">2. Where to Go in the Menu</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-slate-500">
                        <th class="py-2">Menu</th>
                        <th class="py-2">Use It For</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Overview</td><td class="py-2">Fleet summary and health.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Devices</td><td class="py-2">Device list, details, enrollment, re-enroll, status updates.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Groups</td><td class="py-2">Target collections of devices.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Software Packages</td><td class="py-2">Create package catalog and deploy versions.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Policy Center</td><td class="py-2">Manage policies, catalog presets, and categories.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Jobs</td><td class="py-2">Track queued/running/completed operations.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Deployment Center</td><td class="py-2">Agent Delivery and IP Deployment tools.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Settings</td><td class="py-2">System-level operational settings.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Access Control</td><td class="py-2">Users, roles, and permissions.</td></tr>
                    <tr class="border-b align-top"><td class="py-2 font-medium">Docs</td><td class="py-2">Detailed technical documentation and runbooks.</td></tr>
                    <tr class="align-top"><td class="py-2 font-medium">Audit Logs</td><td class="py-2">Action history for compliance and troubleshooting.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-3">
        <h3 class="text-lg font-semibold">3. How to Start (Recommended Flow)</h3>
        <ol class="list-decimal pl-5 text-sm text-slate-700 space-y-2">
            <li>Create or verify an agent release in <span class="font-medium">Deployment Center → Agent Delivery</span>.</li>
            <li>Generate an enrollment token in <span class="font-medium">Devices</span> and run the installer on target endpoints.</li>
            <li>Create device groups in <span class="font-medium">Groups</span> for targeting.</li>
            <li>Prepare policy presets in <span class="font-medium">Policy Center → Policy Catalog</span>.</li>
            <li>Publish and assign policies in <span class="font-medium">Policy Center → Policies</span>.</li>
            <li>Deploy software from <span class="font-medium">Software Packages</span>.</li>
            <li>Monitor progress and errors in <span class="font-medium">Jobs</span> and <span class="font-medium">Audit Logs</span>.</li>
        </ol>
    </section>

    <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
        <h3 class="text-lg font-semibold">Admin Notes</h3>
        <ul class="mt-2 space-y-1 text-sm text-amber-900">
            <li>Use policy entries that include both apply and remove logic for safe rollback.</li>
            <li>For custom policy definitions, validate JSON/command format before assigning broadly.</li>
            <li>Test new policies on a pilot group before full fleet rollout.</li>
        </ul>
    </section>
</x-admin-layout>
