<x-admin-layout title="AI Ops Assistant" heading="AI Ops Assistant">
    @php
        $heroBadges = [
            ['class' => 'ei-chip ei-chip-primary', 'label' => 'Messages: '.((int) ($metrics['assistant_messages'] ?? 0))],
            ['class' => 'ei-chip', 'label' => 'Open incidents: '.((int) ($metrics['open_incidents'] ?? 0))],
            ['class' => 'ei-chip', 'label' => 'Pending approvals: '.((int) ($metrics['pending_approvals'] ?? 0))],
        ];
        $heroActions = [
            ['href' => route('admin.intelligence.risk'), 'label' => 'Open Risk'],
            ['href' => route('admin.intelligence.approvals'), 'label' => 'Open Approvals'],
            ['href' => route('admin.intelligence.remediation'), 'label' => 'Open Remediation'],
            ['href' => route('admin.intelligence.assistant', ['new' => 1]), 'class' => 'ei-button-primary rounded-xl px-4 py-3 text-sm font-medium text-white', 'label' => 'New Chat'],
        ];
        $summaryCards = [
            ['label' => 'Sessions 24h', 'value' => (int) ($metrics['sessions_24h'] ?? 0), 'description' => 'Recent assistant sessions started in the last day.'],
            ['label' => 'Assistant Messages', 'value' => (int) ($metrics['assistant_messages'] ?? 0), 'description' => 'Stored operator and assistant conversation messages.'],
            ['label' => 'Devices With Scores', 'value' => (int) ($metrics['devices_with_scores'] ?? 0), 'description' => 'Endpoints with current intelligence scoring data.'],
            ['class' => 'rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm', 'label_class' => 'text-xs uppercase tracking-[0.18em] text-amber-700', 'value_class' => 'mt-2 text-3xl font-semibold text-amber-900', 'description_class' => 'mt-1 text-sm text-amber-800', 'label' => 'Open Findings', 'value' => (int) ($metrics['open_findings'] ?? 0), 'description' => 'Signals you may want the assistant to explain.'],
        ];
    @endphp
    <div class="endpoint-intelligence-shell space-y-5">
        @include('admin.endpoint-intelligence.partials.smart-nav')
        @include('admin.endpoint-intelligence.partials.overview-hero', [
            'eyebrow' => 'AI Assistant',
            'title' => 'Ask one operational question and get a grounded next step',
            'description' => 'Use the assistant to explain what is happening, identify likely causes, and suggest safe follow-up actions using current endpoint intelligence context.',
            'badges' => $heroBadges,
            'actions' => $heroActions,
        ])

        @include('admin.endpoint-intelligence.partials.overview-stats', [
            'cards' => $summaryCards,
        ])

        <section class="ei-assistant-workspace">
            <aside class="ei-assistant-sidebar">
                <div class="ei-sidebar-head">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Conversations</p>
                        <h3 class="mt-1 text-base font-semibold text-slate-900">Recent investigations</h3>
                    </div>
                    <a href="{{ route('admin.intelligence.assistant', ['new' => 1]) }}" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700">New Chat</a>
                </div>

                <div id="assistant-history-list" class="ei-history-list">
                    @forelse ($recentConversations as $conversation)
                        @php
                            $isSelected = ($selectedConversationId ?? null) === $conversation->id;
                            $conversationUrl = route('admin.intelligence.assistant').'?conversation_id='.$conversation->id;
                        @endphp
                        <a
                            href="{{ $conversationUrl }}"
                            data-conversation-id="{{ $conversation->id }}"
                            class="ei-history-item {{ $isSelected ? 'is-active' : '' }}"
                        >
                            <p class="truncate text-sm font-medium">{{ filled($conversation->title) ? $conversation->title : 'Untitled conversation' }}</p>
                            <p class="mt-1 text-[11px] text-slate-500">{{ optional($conversation->last_message_at ?? $conversation->updated_at)->diffForHumans() ?? 'recent' }}</p>
                        </a>
                    @empty
                        <div class="ei-history-empty">
                            No conversation history yet.
                        </div>
                    @endforelse
                </div>

                <div class="ei-quick-guide">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Workflow</p>
                    <div class="mt-3 grid gap-2">
                        @foreach ($assistantFlow as $step)
                            <div class="ei-guide-step">
                                <p class="text-xs font-semibold text-slate-900">{{ $step['title'] }}</p>
                                <p class="mt-1 text-[11px] text-slate-500">{{ $step['description'] }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 grid gap-2">
                        <a href="{{ route('admin.intelligence.risk') }}" class="ei-side-link">Open Risk Dashboard</a>
                        <a href="{{ route('admin.intelligence.approvals') }}" class="ei-side-link">Open Approval Center</a>
                        <a href="{{ route('admin.intelligence.remediation') }}" class="ei-side-link">Open Remediation Queue</a>
                    </div>
                </div>
            </aside>

            <article class="ei-assistant-panel">
                <header class="ei-panel-head">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Assistant Workspace</p>
                            <h3 class="mt-1 text-lg font-semibold text-slate-900">Ask, review, and act</h3>
                            <p class="mt-1 text-xs text-slate-600">Keep questions short and issue-specific for clearer answers.</p>
                        </div>
                        <a href="{{ route('admin.intelligence.assistant', ['new' => 1]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700">Start Fresh</a>
                    </div>
                    <div id="assistant-active-scope" class="ei-active-scope mt-3">
                        @if (filled($selectedScopeLabels['device'] ?? null))
                            <span class="ei-chip px-2.5 py-1 text-xs">Device: {{ $selectedScopeLabels['device'] }}</span>
                        @endif
                        @if (filled($selectedScopeLabels['group'] ?? null))
                            <span class="ei-chip px-2.5 py-1 text-xs">Group: {{ $selectedScopeLabels['group'] }}</span>
                        @endif
                        @if (filled($selectedScopeLabels['package'] ?? null))
                            <span class="ei-chip px-2.5 py-1 text-xs">Package: {{ $selectedScopeLabels['package'] }}</span>
                        @endif
                        @if (! filled($selectedScopeLabels['device'] ?? null) && ! filled($selectedScopeLabels['group'] ?? null) && ! filled($selectedScopeLabels['package'] ?? null))
                            <span class="ei-chip ei-chip-primary px-2.5 py-1 text-xs">Fleet scope</span>
                        @endif
                    </div>
                </header>

                <div class="ei-thread-head">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Conversation Output</p>
                        <h4 class="mt-1 text-sm font-semibold text-slate-900">Answers with evidence, reasoning, and next actions</h4>
                    </div>
                </div>

                <div id="assistant-thread" class="ei-chat-thread">
                    @forelse ($recentMessages as $message)
                        <div class="ei-msg-row {{ $message->role === 'user' ? 'is-user' : 'is-assistant' }}" data-chat-role="{{ $message->role }}">
                            <div class="ei-msg-bubble">
                                <div class="ei-msg-meta">
                                    <span>{{ $message->role }}</span>
                                    <span>{{ optional($message->created_at)->diffForHumans() }}</span>
                                </div>
                                <p class="ei-msg-content">{{ $message->content }}</p>
                                @if ($message->role === 'assistant' && is_array($message->citations) && count($message->citations) > 0)
                                    <p class="mt-2 text-[11px] text-slate-500">Citations: {{ implode(', ', $message->citations) }}</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div id="assistant-thread-empty" class="ei-thread-empty">
                            Start with a direct question. Keep one issue per message for faster and clearer results.
                        </div>
                    @endforelse
                </div>

                <div id="assistant-status" class="ei-status-bar">
                    Ready.
                </div>

                <form id="assistant-form" class="ei-assistant-form">
                    <input type="hidden" id="assistant-conversation-id" name="conversation_id" value="{{ $selectedConversationId ?? '' }}">

                    <div class="ei-scope-grid">
                        <label class="ei-field">
                            <span class="ei-field-label">Mode</span>
                            <select id="assistant-mode" name="mode" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="investigate" @selected(($selectedMode ?? 'investigate') === 'investigate')>Investigate</option>
                                <option value="explain" @selected(($selectedMode ?? '') === 'explain')>Explain</option>
                                <option value="recommend" @selected(($selectedMode ?? '') === 'recommend')>Recommend</option>
                                <option value="guided_fix" @selected(($selectedMode ?? '') === 'guided_fix')>Guided Fix</option>
                            </select>
                        </label>

                        <label class="ei-field">
                            <span class="ei-field-label">Device (optional)</span>
                            <select id="assistant-device-id" name="device_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">Fleet / auto-detect</option>
                                @foreach ($devices as $device)
                                    <option value="{{ $device->id }}" @selected(($selectedScope['device_id'] ?? '') === $device->id)>{{ $device->hostname }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="ei-field">
                            <span class="ei-field-label">Group (optional)</span>
                            <select id="assistant-group-id" name="group_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">None</option>
                                @foreach ($groups as $group)
                                    <option value="{{ $group->id }}" @selected(($selectedScope['group_id'] ?? '') === $group->id)>{{ $group->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="ei-field">
                            <span class="ei-field-label">Package (optional)</span>
                            <select id="assistant-package-id" name="package_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">None</option>
                                @foreach ($packages as $package)
                                    <option value="{{ $package->id }}" @selected(($selectedScope['package_id'] ?? '') === $package->id)>{{ $package->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="ei-prompt-row mt-3">
                        @foreach ($quickPrompts as $prompt)
                            <button type="button" class="ei-prompt-chip" data-assistant-prompt="{{ $prompt }}">{{ $prompt }}</button>
                        @endforeach
                    </div>

                    <div class="ei-composer-row mt-3">
                        <div class="ei-composer-main">
                            <label for="assistant-question" class="ei-field-label">Question</label>
                            <textarea
                                id="assistant-question"
                                name="question"
                                rows="3"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                                placeholder="Example: Which endpoints need action now, and what can be safely remediated without approval?"
                            ></textarea>
                        </div>
                        <button type="submit" class="ei-button-primary rounded-xl border px-4 py-3 text-sm font-medium">Ask Assistant</button>
                    </div>
                </form>
            </article>
        </section>
    </div>

    <style>
        .endpoint-intelligence-shell .ei-assistant-workspace {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            gap: 1rem;
            align-items: stretch;
        }

        .endpoint-intelligence-shell .ei-assistant-sidebar,
        .endpoint-intelligence-shell .ei-assistant-panel {
            border: 1px solid var(--ei-border);
            border-radius: var(--brand-radius-2xl);
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
            min-width: 0;
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-assistant-sidebar,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-assistant-panel {
            background: #111c2d;
            box-shadow: 0 12px 28px rgba(2, 6, 23, 0.22);
        }

        .endpoint-intelligence-shell .ei-assistant-sidebar {
            display: flex;
            flex-direction: column;
            min-height: 680px;
        }

        .endpoint-intelligence-shell .ei-sidebar-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.22);
        }

        .endpoint-intelligence-shell .ei-history-list {
            max-height: 360px;
            min-height: 260px;
            overflow-y: auto;
            padding: 0.75rem;
            display: grid;
            gap: 0.5rem;
        }

        .endpoint-intelligence-shell .ei-history-item {
            display: block;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: var(--brand-radius-lg);
            background: #ffffff;
            color: rgb(51 65 85);
            padding: 0.54rem 0.62rem;
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-history-item,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-side-link {
            background: #0f172a;
            color: rgb(226 232 240);
        }

        .endpoint-intelligence-shell .ei-history-item.is-active {
            border-color: rgba(14, 116, 144, 0.45);
            background: rgba(14, 116, 144, 0.08);
            color: rgb(15 23 42);
        }

        .endpoint-intelligence-shell .ei-history-empty {
            border: 1px dashed rgba(148, 163, 184, 0.42);
            border-radius: var(--brand-radius-lg);
            background: var(--brand-background);
            color: rgb(100 116 139);
            padding: 0.8rem;
            font-size: 0.84rem;
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-history-empty,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-quick-guide {
            background: #0f172a;
            color: rgb(148 163 184);
        }

        .endpoint-intelligence-shell .ei-quick-guide {
            margin: 0.75rem;
            margin-top: auto;
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: var(--brand-radius-xl);
            background: #f8fafc;
            padding: 0.85rem;
        }

        .endpoint-intelligence-shell .ei-guide-step {
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: var(--brand-radius-lg);
            background: #ffffff;
            padding: 0.65rem 0.7rem;
        }

        .endpoint-intelligence-shell .ei-side-link {
            display: block;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: var(--brand-radius-lg);
            background: #ffffff;
            color: rgb(51 65 85);
            font-size: 0.75rem;
            padding: 0.44rem 0.6rem;
        }

        .endpoint-intelligence-shell .ei-side-link:hover {
            border-color: rgba(14, 116, 144, 0.4);
            background: rgba(14, 116, 144, 0.05);
            color: rgb(8 47 73);
        }

        .endpoint-intelligence-shell .ei-assistant-panel {
            display: flex;
            flex-direction: column;
            min-height: 680px;
            overflow: hidden;
        }

        .endpoint-intelligence-shell .ei-panel-head {
            padding: 1rem 1rem 0.9rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.22);
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-panel-head {
            background: linear-gradient(180deg, #111c2d 0%, #0f172a 100%);
        }

        .endpoint-intelligence-shell .ei-active-scope {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .endpoint-intelligence-shell .ei-assistant-form {
            border-top: 1px solid rgba(148, 163, 184, 0.22);
            background: #ffffff;
            padding: 1rem;
        }

        .endpoint-intelligence-shell .ei-scope-grid {
            display: grid;
            gap: 0.65rem;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .endpoint-intelligence-shell .ei-field {
            display: grid;
            gap: 0.3rem;
        }

        .endpoint-intelligence-shell .ei-field-label {
            font-size: 0.66rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: rgb(100 116 139);
        }

        .endpoint-intelligence-shell .ei-prompt-row {
            display: flex;
            flex-wrap: nowrap;
            gap: 0.45rem;
            overflow-x: auto;
            padding-bottom: 0.2rem;
        }

        .endpoint-intelligence-shell .ei-prompt-chip {
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 9999px;
            background: #ffffff;
            color: rgb(51 65 85);
            padding: 0.32rem 0.66rem;
            font-size: 0.72rem;
            line-height: 1.2;
            white-space: nowrap;
            flex: 0 0 auto;
        }

        .endpoint-intelligence-shell .ei-prompt-chip:hover {
            border-color: rgba(14, 116, 144, 0.42);
            background: rgba(14, 116, 144, 0.06);
            color: rgb(8 47 73);
        }

        .endpoint-intelligence-shell .ei-composer-row {
            display: flex;
            align-items: flex-end;
            gap: 0.75rem;
        }

        .endpoint-intelligence-shell .ei-composer-main {
            flex: 1;
        }

        .endpoint-intelligence-shell #assistant-question {
            min-height: 76px;
            max-height: 150px;
            resize: vertical;
        }

        .endpoint-intelligence-shell .ei-thread-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.9rem 1rem 0.75rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18);
            background: #ffffff;
        }

        .endpoint-intelligence-shell .ei-chat-thread {
            flex: 1;
            min-height: 320px;
            max-height: 56vh;
            overflow-y: auto;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 1rem;
            display: grid;
            align-content: start;
            gap: 0.7rem;
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-chat-thread {
            background: linear-gradient(180deg, #0f172a 0%, #020617 100%);
        }

        .endpoint-intelligence-shell .ei-thread-empty {
            border: 1px dashed rgba(148, 163, 184, 0.5);
            border-radius: var(--brand-radius-xl);
            background: rgba(255, 255, 255, 0.72);
            color: rgb(100 116 139);
            padding: 1rem;
            font-size: 0.88rem;
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-thread-empty,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-detail,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-status-bar {
            background: #0f172a;
            color: rgb(148 163 184);
        }

        .endpoint-intelligence-shell .ei-msg-row {
            display: flex;
        }

        .endpoint-intelligence-shell .ei-msg-row.is-user {
            justify-content: flex-end;
        }

        .endpoint-intelligence-shell .ei-msg-row.is-assistant {
            justify-content: flex-start;
        }

        .endpoint-intelligence-shell .ei-msg-bubble {
            width: fit-content;
            max-width: min(92%, 50rem);
            border: 1px solid rgba(148, 163, 184, 0.26);
            border-radius: var(--brand-radius-xl);
            padding: 0.6rem 0.74rem;
            background: #ffffff;
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-msg-bubble,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-detail-item,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-detail summary,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-response-kpis .chip {
            background: #111c2d;
            color: rgb(226 232 240);
        }

        .endpoint-intelligence-shell .ei-msg-row.is-user .ei-msg-bubble {
            border-color: rgba(14, 116, 144, 0.32);
            background: rgba(14, 116, 144, 0.08);
            max-width: min(76%, 42rem);
        }

        .endpoint-intelligence-shell .ei-msg-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.65rem;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgb(100 116 139);
        }

        .endpoint-intelligence-shell .ei-msg-content {
            margin-top: 0.42rem;
            white-space: pre-wrap;
            color: rgb(30 41 59);
            font-size: 0.9rem;
            line-height: 1.45;
        }

        .endpoint-intelligence-shell .ei-response-kpis {
            margin-top: 0.55rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .endpoint-intelligence-shell .ei-response-kpis .chip {
            border: 1px solid rgba(148, 163, 184, 0.32);
            border-radius: 9999px;
            padding: 0.18rem 0.52rem;
            font-size: 0.68rem;
            font-weight: 600;
            color: rgb(51 65 85);
            background: #ffffff;
        }

        .endpoint-intelligence-shell .ei-detail {
            margin-top: 0.52rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: var(--brand-radius-lg);
            background: #f8fafc;
            overflow: hidden;
        }

        .endpoint-intelligence-shell .ei-detail summary {
            cursor: pointer;
            list-style: none;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: rgb(51 65 85);
            padding: 0.55rem 0.62rem;
            background: #ffffff;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .endpoint-intelligence-shell .ei-detail summary::-webkit-details-marker {
            display: none;
        }

        .endpoint-intelligence-shell .ei-detail-list {
            display: grid;
            gap: 0.5rem;
            padding: 0.55rem;
        }

        .endpoint-intelligence-shell .ei-detail-item {
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: var(--brand-radius-md);
            background: #ffffff;
            padding: 0.5rem;
            font-size: 0.82rem;
            color: rgb(30 41 59);
            line-height: 1.35;
        }

        .endpoint-intelligence-shell .ei-detail-item-meta {
            margin-top: 0.24rem;
            font-size: 0.7rem;
            color: rgb(100 116 139);
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-msg-meta,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-detail-item-meta,
        html[data-theme="dark"] .endpoint-intelligence-shell .ei-field-label {
            color: rgb(148 163 184);
        }

        html[data-theme="dark"] .endpoint-intelligence-shell .ei-msg-content {
            color: rgb(226 232 240);
        }

        .endpoint-intelligence-shell .ei-detail-code {
            margin-top: 0.32rem;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: var(--brand-radius-sm);
            background: #0f172a;
            color: #e2e8f0;
            padding: 0.45rem;
            font-size: 0.72rem;
            overflow-x: auto;
        }

        .endpoint-intelligence-shell .ei-status-bar {
            margin: 0.8rem 1rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: var(--brand-radius-lg);
            background: #f8fafc;
            padding: 0.6rem 0.75rem;
            font-size: 0.76rem;
            color: rgb(71 85 105);
        }

        @media (max-width: 1279px) {
            .endpoint-intelligence-shell .ei-assistant-workspace {
                grid-template-columns: 1fr;
            }

            .endpoint-intelligence-shell .ei-assistant-sidebar {
                min-height: 0;
            }

            .endpoint-intelligence-shell .ei-assistant-panel {
                min-height: 560px;
            }

            .endpoint-intelligence-shell .ei-scope-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .endpoint-intelligence-shell .ei-scope-grid {
                grid-template-columns: 1fr;
            }

            .endpoint-intelligence-shell .ei-composer-row {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>

    <script>
        (function () {
            const askUrl = @json(route('admin.intelligence.assistant.ask'));
            const assistantPageUrl = @json(route('admin.intelligence.assistant'));
            const csrf = @json(csrf_token());

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function asArray(value) {
                return Array.isArray(value) ? value : [];
            }

            function formatPercent(value) {
                const numeric = Number(value);
                if (Number.isNaN(numeric)) {
                    return 'n/a';
                }
                return `${Math.round(numeric * 100)}%`;
            }

            function renderList(items, formatter) {
                const rows = asArray(items);
                if (rows.length === 0) {
                    return '<div class=\"ei-detail-item text-slate-500\">No items.</div>';
                }
                return rows.map((row, index) => formatter(row, index)).join('');
            }

            function formatActionArguments(value) {
                if (!value || typeof value !== 'object') {
                    return '';
                }
                const keys = Object.keys(value);
                if (!keys.length) {
                    return '';
                }
                return `<pre class=\"ei-detail-code\">${escapeHtml(JSON.stringify(value, null, 2))}</pre>`;
            }

            function renderAssistantBubble(answer, meta) {
                const reasoning = String(answer?.reasoning_summary ?? 'Assistant response received.');
                const riskLevel = String(answer?.risk_level ?? 'unknown');
                const confidence = formatPercent(answer?.confidence_score);
                const requiresHuman = Boolean(answer?.requires_human_review);
                const approvalRequired = Boolean(answer?.approval_required);
                const rollbackPossible = Boolean(answer?.rollback_possible);
                const citations = asArray(answer?.citations);

                const factsHtml = renderList(answer?.known_facts, (fact) => {
                    const statement = escapeHtml(fact?.statement ?? '');
                    const citationLabel = asArray(fact?.citations).join(', ');
                    return `<div class=\"ei-detail-item\">${statement}<div class=\"ei-detail-item-meta\">${escapeHtml(citationLabel || 'Sources: n/a')}</div></div>`;
                });

                const inferencesHtml = renderList(answer?.inferences, (inference) => {
                    const statement = escapeHtml(inference?.statement ?? '');
                    const citationLabel = asArray(inference?.citations).join(', ');
                    const confidenceText = formatPercent(inference?.confidence);
                    return `<div class=\"ei-detail-item\">${statement}<div class=\"ei-detail-item-meta\">Confidence ${escapeHtml(confidenceText)} | Sources: ${escapeHtml(citationLabel || 'n/a')}</div></div>`;
                });

                const actions = asArray(answer?.recommended_actions);
                const actionsHtml = renderList(actions, (action) => {
                    const actionType = escapeHtml(action?.action_type ?? 'unknown_action');
                    const targetType = escapeHtml(action?.target_scope?.type ?? 'unknown');
                    const targetId = escapeHtml(action?.target_scope?.id ?? 'n/a');
                    const why = escapeHtml(action?.why_this_action ?? answer?.why_this_action ?? '');
                    const actionRollback = action?.rollback_possible ? 'yes' : 'no';
                    const actionApproval = action?.approval_required ? 'yes' : 'no';
                    return `
                        <div class=\"ei-detail-item\">
                            <div class=\"font-semibold text-slate-900\">${actionType}</div>
                            <div class=\"ei-detail-item-meta\">Target ${targetType}:${targetId}</div>
                            <div class=\"ei-detail-item-meta\">Approval required: ${actionApproval} | Rollback possible: ${actionRollback}</div>
                            ${why ? `<div class=\"mt-1\">${why}</div>` : ''}
                            ${formatActionArguments(action?.arguments)}
                        </div>
                    `;
                });

                const gapsHtml = renderList(answer?.context_gaps, (gap) => `<div class=\"ei-detail-item\">${escapeHtml(gap)}</div>`);
                const citationHtml = citations.length
                    ? `<div class=\"ei-detail-item\">${escapeHtml(citations.join(', '))}</div>`
                    : '<div class=\"ei-detail-item text-slate-500\">No citations provided.</div>';

                return `
                    <div class=\"ei-msg-row is-assistant\" data-chat-role=\"assistant\">
                        <div class=\"ei-msg-bubble\">
                            <div class=\"ei-msg-meta\">
                                <span>assistant</span>
                                <span>${escapeHtml(meta || 'now')}</span>
                            </div>
                            <p class=\"ei-msg-content\">${escapeHtml(reasoning)}</p>
                            <div class=\"ei-response-kpis\">
                                <span class=\"chip\">Risk: ${escapeHtml(riskLevel)}</span>
                                <span class=\"chip\">Confidence: ${escapeHtml(confidence)}</span>
                                <span class=\"chip\">Human review: ${requiresHuman ? 'yes' : 'no'}</span>
                                <span class=\"chip\">Approval: ${approvalRequired ? 'required' : 'not required'}</span>
                                <span class=\"chip\">Rollback: ${rollbackPossible ? 'possible' : 'not guaranteed'}</span>
                            </div>
                            <details class=\"ei-detail\" open>
                                <summary>Recommended Actions (${actions.length})</summary>
                                <div class=\"ei-detail-list\">${actionsHtml}</div>
                            </details>
                            <details class=\"ei-detail\">
                                <summary>Known Facts</summary>
                                <div class=\"ei-detail-list\">${factsHtml}</div>
                            </details>
                            <details class=\"ei-detail\">
                                <summary>Inferences</summary>
                                <div class=\"ei-detail-list\">${inferencesHtml}</div>
                            </details>
                            <details class=\"ei-detail\">
                                <summary>Context Gaps</summary>
                                <div class=\"ei-detail-list\">${gapsHtml}</div>
                            </details>
                            <details class=\"ei-detail\">
                                <summary>Citations</summary>
                                <div class=\"ei-detail-list\">${citationHtml}</div>
                            </details>
                        </div>
                    </div>
                `;
            }

            function renderPlainBubble(role, content, meta, citations) {
                const isUser = role === 'user';
                const citationText = Array.isArray(citations) && citations.length
                    ? `<p class=\"mt-2 text-[11px] text-slate-500\">Citations: ${escapeHtml(citations.join(', '))}</p>`
                    : '';
                return `
                    <div class=\"ei-msg-row ${isUser ? 'is-user' : 'is-assistant'}\" data-chat-role=\"${escapeHtml(role)}\">
                        <div class=\"ei-msg-bubble\">
                            <div class=\"ei-msg-meta\">
                                <span>${escapeHtml(role)}</span>
                                <span>${escapeHtml(meta || 'now')}</span>
                            </div>
                            <p class=\"ei-msg-content\">${escapeHtml(content)}</p>
                            ${citationText}
                        </div>
                    </div>
                `;
            }

            function appendHtml(html) {
                const thread = document.getElementById('assistant-thread');
                const empty = document.getElementById('assistant-thread-empty');
                if (!thread) return null;
                if (empty) empty.remove();
                const wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                const node = wrapper.firstElementChild;
                if (!node) return null;
                thread.appendChild(node);
                thread.scrollTop = thread.scrollHeight;
                return node;
            }

            function appendPlainMessage(role, content, meta, citations) {
                return appendHtml(renderPlainBubble(role, content, meta, citations));
            }

            function appendAssistantMessage(answer, meta) {
                return appendHtml(renderAssistantBubble(answer, meta));
            }

            function setStatus(text, tone) {
                const box = document.getElementById('assistant-status');
                if (!box) return;
                box.textContent = text;
                if (tone === 'error') {
                    box.className = 'ei-status-bar border-rose-200 bg-rose-50 text-rose-700';
                    return;
                }
                if (tone === 'working') {
                    box.className = 'ei-status-bar border-amber-200 bg-amber-50 text-amber-800';
                    return;
                }
                box.className = 'ei-status-bar';
            }

            function markActiveConversation(conversationId) {
                document.querySelectorAll('[data-conversation-id]').forEach((element) => {
                    if (!(element instanceof HTMLElement)) return;
                    const isActive = element.dataset.conversationId === conversationId;
                    if (isActive) {
                        element.classList.add('is-active');
                    } else {
                        element.classList.remove('is-active');
                    }
                });
            }

            function upsertConversationInHistory(conversationId, title) {
                const list = document.getElementById('assistant-history-list');
                if (!list || !conversationId) return;

                let existing = null;
                list.querySelectorAll('[data-conversation-id]').forEach((item) => {
                    if (!(item instanceof HTMLElement)) return;
                    if (item.dataset.conversationId === String(conversationId)) {
                        existing = item;
                    }
                });

                if (existing instanceof HTMLElement) {
                    const label = existing.querySelector('p');
                    if (label && title) label.textContent = title;
                    const meta = existing.querySelector('p + p');
                    if (meta) meta.textContent = 'just now';
                    list.prepend(existing);
                    markActiveConversation(String(conversationId));
                    return;
                }

                const anchor = document.createElement('a');
                anchor.href = `${assistantPageUrl}?conversation_id=${encodeURIComponent(conversationId)}`;
                anchor.dataset.conversationId = String(conversationId);
                anchor.className = 'ei-history-item is-active';
                anchor.innerHTML = `<p class=\"truncate text-sm font-medium\">${escapeHtml(title || 'Untitled conversation')}</p><p class=\"mt-1 text-[11px] text-slate-500\">just now</p>`;

                const emptyState = list.querySelector('.ei-history-empty');
                if (emptyState) emptyState.remove();

                list.prepend(anchor);
                markActiveConversation(String(conversationId));
            }

            function updateActiveScope() {
                const scope = document.getElementById('assistant-active-scope');
                const deviceSelect = document.getElementById('assistant-device-id');
                const groupSelect = document.getElementById('assistant-group-id');
                const packageSelect = document.getElementById('assistant-package-id');
                if (!scope || !deviceSelect || !groupSelect || !packageSelect) return;

                const chips = [];
                if (deviceSelect.value) {
                    chips.push(`Device: ${deviceSelect.options[deviceSelect.selectedIndex]?.text || deviceSelect.value}`);
                }
                if (groupSelect.value) {
                    chips.push(`Group: ${groupSelect.options[groupSelect.selectedIndex]?.text || groupSelect.value}`);
                }
                if (packageSelect.value) {
                    chips.push(`Package: ${packageSelect.options[packageSelect.selectedIndex]?.text || packageSelect.value}`);
                }
                if (!chips.length) {
                    chips.push('Fleet scope');
                }

                scope.innerHTML = chips.map((label, index) => {
                    const className = index === 0 && label === 'Fleet scope'
                        ? 'ei-chip ei-chip-primary px-2.5 py-1 text-xs'
                        : 'ei-chip px-2.5 py-1 text-xs';
                    return `<span class=\"${className}\">${escapeHtml(label)}</span>`;
                }).join('');
            }

            document.querySelectorAll('[data-assistant-prompt]').forEach((element) => {
                element.addEventListener('click', function () {
                    const prompt = String(this.getAttribute('data-assistant-prompt') ?? '').trim();
                    const textarea = document.getElementById('assistant-question');
                    if (!(textarea instanceof HTMLTextAreaElement)) return;
                    textarea.value = prompt;
                    textarea.focus();
                });
            });

            ['assistant-device-id', 'assistant-group-id', 'assistant-package-id'].forEach((id) => {
                document.getElementById(id)?.addEventListener('change', updateActiveScope);
            });

            document.getElementById('assistant-form')?.addEventListener('submit', async function (event) {
                event.preventDefault();
                const form = event.currentTarget;
                const payload = Object.fromEntries(new FormData(form).entries());
                payload.question = String(payload.question ?? '').trim();

                if (!payload.question) {
                    setStatus('Question is required.', 'error');
                    return;
                }

                ['device_id', 'group_id', 'package_id', 'conversation_id', 'mode'].forEach((key) => {
                    if (payload[key] === '') delete payload[key];
                });

                const submitButton = form.querySelector('button[type=\"submit\"]');
                if (submitButton instanceof HTMLButtonElement) {
                    submitButton.disabled = true;
                    submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                }

                appendPlainMessage('user', payload.question, 'just now', []);
                const typing = appendPlainMessage('assistant', 'Analyzing context...', 'now', []);
                if (typing) typing.id = 'assistant-typing';

                const questionInput = document.getElementById('assistant-question');
                if (questionInput instanceof HTMLTextAreaElement) questionInput.value = '';
                setStatus('Assistant is working...', 'working');

                try {
                    const response = await fetch(askUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify(payload),
                    });

                    const raw = await response.text();
                    const json = raw ? JSON.parse(raw) : {};
                    document.getElementById('assistant-typing')?.remove();

                    if (!response.ok) {
                        appendPlainMessage('assistant', json.message ?? 'Assistant request failed.', 'now', []);
                        setStatus(`Request failed (HTTP ${response.status}).`, 'error');
                        return;
                    }

                    const answer = json?.answer ?? {};
                    appendAssistantMessage(answer, 'now');
                    setStatus(`Response ready. Risk: ${String(answer?.risk_level ?? 'n/a')}.`, 'ok');

                    const hiddenConversation = document.getElementById('assistant-conversation-id');
                    if (hiddenConversation && json?.conversation_id) {
                        hiddenConversation.value = json.conversation_id;
                        upsertConversationInHistory(json.conversation_id, payload.question.slice(0, 120));
                        markActiveConversation(json.conversation_id);
                        history.replaceState({}, '', `${assistantPageUrl}?conversation_id=${encodeURIComponent(json.conversation_id)}`);
                    }
                } catch (error) {
                    document.getElementById('assistant-typing')?.remove();
                    appendPlainMessage('assistant', 'Assistant request failed before server response.', 'now', []);
                    setStatus(error instanceof Error ? error.message : String(error), 'error');
                } finally {
                    if (submitButton instanceof HTMLButtonElement) {
                        submitButton.disabled = false;
                        submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
                    }
                }
            });

            document.getElementById('assistant-question')?.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    document.getElementById('assistant-form')?.requestSubmit();
                }
            });

            const thread = document.getElementById('assistant-thread');
            if (thread) thread.scrollTop = thread.scrollHeight;
            updateActiveScope();
        })();
    </script>
</x-admin-layout>
