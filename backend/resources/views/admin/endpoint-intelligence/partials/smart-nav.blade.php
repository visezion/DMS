@php
    $viewer = auth()->user();
    $can = static function (?string $permission) use ($viewer): bool {
        if ($viewer === null || $permission === null || $permission === '') {
            return false;
        }

        return method_exists($viewer, 'hasPermission') ? $viewer->hasPermission($permission) : false;
    };

    $pagePatterns = [
        'health' => ['admin.intelligence.health*'],
        'risk' => ['admin.intelligence.risk*', 'admin.intelligence.executive*'],
        'incidents' => ['admin.intelligence.incidents*'],
        'assistant' => ['admin.intelligence.assistant*'],
        'approvals' => ['admin.intelligence.approvals*'],
        'remediation' => ['admin.intelligence.remediation*', 'admin.intelligence.actions*'],
        'autonomy' => ['admin.intelligence.autonomy*', 'admin.intelligence.autonomous*', 'admin.intelligence.tuning*'],
    ];

    $currentKey = array_key_first($pagePatterns);
    foreach ($pagePatterns as $key => $patterns) {
        foreach ($patterns as $pattern) {
            if (request()->routeIs($pattern)) {
                $currentKey = $key;
                break 2;
            }
        }
    }

    $pageGuides = [
        'health' => [
            'stage' => 'Detect',
            'message' => 'Start here to see which devices need attention first and whether the telemetry is fresh enough to trust.',
            'next' => [
                ['label' => 'Open Risk', 'route' => route('admin.intelligence.risk'), 'permission' => 'risk.read'],
                ['label' => 'Ask Assistant', 'route' => route('admin.intelligence.assistant'), 'permission' => 'assistant.use'],
            ],
        ],
        'risk' => [
            'stage' => 'Investigate',
            'message' => 'Use risk to understand why an endpoint or the fleet is unsafe and which signals are driving priority.',
            'next' => [
                ['label' => 'Open Incidents', 'route' => route('admin.intelligence.incidents'), 'permission' => 'incidents.read'],
                ['label' => 'Open Remediation', 'route' => route('admin.intelligence.remediation'), 'permission' => 'remediation.read'],
            ],
        ],
        'incidents' => [
            'stage' => 'Correlate',
            'message' => 'Incidents group related signals into one narrative so the operator can follow the event from cause to response.',
            'next' => [
                ['label' => 'Open Approvals', 'route' => route('admin.intelligence.approvals'), 'permission' => 'remediation.approve'],
                ['label' => 'Open Remediation', 'route' => route('admin.intelligence.remediation'), 'permission' => 'remediation.read'],
            ],
        ],
        'assistant' => [
            'stage' => 'Explain',
            'message' => 'Use the assistant when you want a faster explanation, likely causes, or safer next steps without reading every raw signal yourself.',
            'next' => [
                ['label' => 'Open Risk', 'route' => route('admin.intelligence.risk'), 'permission' => 'risk.read'],
                ['label' => 'Open Remediation', 'route' => route('admin.intelligence.remediation'), 'permission' => 'remediation.read'],
            ],
        ],
        'approvals' => [
            'stage' => 'Decide',
            'message' => 'Approvals is the human checkpoint before higher-impact response actions are allowed to run.',
            'next' => [
                ['label' => 'Approve or reject now', 'route' => route('admin.intelligence.approvals'), 'permission' => 'remediation.approve'],
                ['label' => 'Track in Remediation', 'route' => route('admin.intelligence.remediation'), 'permission' => 'remediation.read'],
            ],
        ],
        'remediation' => [
            'stage' => 'Act',
            'message' => 'Remediation is where plans are validated, executed, and monitored until they succeed or require rollback.',
            'next' => [
                ['label' => 'Review Action History', 'route' => route('admin.intelligence.actions'), 'permission' => 'remediation.read'],
                ['label' => 'Adjust Autonomy', 'route' => route('admin.intelligence.autonomy'), 'permission' => 'autonomy.manage'],
            ],
        ],
        'autonomy' => [
            'stage' => 'Control',
            'message' => 'Autonomy defines how much the platform can do automatically and where human approval must still apply.',
            'next' => [
                ['label' => 'Open Remediation', 'route' => route('admin.intelligence.remediation'), 'permission' => 'remediation.read'],
                ['label' => 'Ask Assistant', 'route' => route('admin.intelligence.assistant'), 'permission' => 'assistant.use'],
            ],
        ],
    ];

    $currentGuide = $pageGuides[$currentKey] ?? null;
    $nextActions = collect($currentGuide['next'] ?? [])
        ->filter(fn (array $action) => $can($action['permission'] ?? null))
        ->values();
@endphp

@if ($currentGuide)
    <section class="ei-smart-nav rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="ei-smart-nav__head">
            <div>
                <p class="ei-smart-nav__eyebrow">Endpoint Intelligence Workflow</p>
                <h3 class="ei-smart-nav__title">Use the sidebar for navigation. Use this panel for guidance.</h3>
            </div>
            <span class="ei-chip ei-chip-primary px-3 py-1 text-xs font-medium">Current stage: {{ $currentGuide['stage'] }}</span>
        </div>

        <div class="ei-smart-nav__guide">
            <div>
                <p class="ei-smart-nav__guide-label">What this page is for</p>
                <p class="ei-smart-nav__guide-text">{{ $currentGuide['message'] }}</p>
            </div>
            @if ($nextActions->isNotEmpty())
                <div class="ei-smart-nav__guide-actions">
                    @foreach ($nextActions as $action)
                        <a href="{{ $action['route'] }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700">{{ $action['label'] }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endif
