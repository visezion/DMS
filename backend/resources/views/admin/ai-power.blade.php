<x-admin-layout title="AI Power" heading="AI Power Landing">
    <div class="space-y-4 ai-power-page">
        <style>
            .ai-power-page .ai-chat-scroll {
                scrollbar-width: thin;
                scrollbar-color: #94a3b8 transparent;
            }
            .ai-power-page .ai-chat-scroll::-webkit-scrollbar {
                width: 8px;
            }
            .ai-power-page .ai-chat-scroll::-webkit-scrollbar-thumb {
                background: #94a3b8;
                border-radius: 999px;
            }
            .ai-power-page .ai-shell-backdrop {
                background: #f8fafc;
            }
            .ai-power-page .ai-chat-shell {
                display: flex;
                flex-direction: column;
                height: min(78vh, 640px);
                min-height: 560px;
            }
            .ai-power-page .ai-chat-history {
                flex: 1 1 auto;
                min-height: 0;
                overflow-y: auto;
            }
            .ai-power-page .ai-chat-composer-wrap {
                position: sticky;
                bottom: 0;
                z-index: 5;
            }
            @media (max-width: 1024px) {
                .ai-power-page .ai-chat-shell {
                    height: min(74vh, 740px);
                    min-height: 480px;
                }
            }
            .ai-power-page .ai-msg-row {
                display: flex;
                align-items: flex-end;
                gap: 0.55rem;
            }
            .ai-power-page .ai-msg-avatar {
                height: 1.8rem;
                width: 1.8rem;
                border-radius: 999px;
                font-size: 0.65rem;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .ai-power-page .ai-bubble {
                max-width: min(90%, 52rem);
                border-radius: 1rem;
                border: 1px solid #dbe7f3;
                padding: 0.68rem 0.8rem;
            }
            .ai-power-page .ai-composer {
                display: flex;
                align-items: stretch;
                border: 1px solid #cbd5e1;
                border-radius: 0.9rem;
                overflow: hidden;
                background: #ffffff;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
            }
            .ai-power-page .ai-composer:focus-within {
                border-color: #38bdf8;
                box-shadow: 0 0 0 3px rgba(125, 211, 252, 0.24);
            }
            .ai-power-page .ai-composer-input {
                flex: 1 1 auto;
                border: 0;
                border-radius: 0;
                resize: vertical;
                min-height: 82px;
                padding: 0.75rem 0.85rem;
                background: transparent;
            }
            .ai-power-page .ai-composer-input:focus {
                outline: none;
                box-shadow: none;
            }
            .ai-power-page .ai-composer-send {
                border: 0;
                border-left: 1px solid #cbd5e1;
                border-radius: 0;
                min-width: 7.2rem;
                font-size: 0.84rem;
            }
            .ai-power-page .ai-typing {
                display: inline-flex;
                align-items: center;
                gap: 0.28rem;
                min-height: 0.85rem;
            }
            .ai-power-page .ai-typing span {
                width: 0.38rem;
                height: 0.38rem;
                border-radius: 999px;
                background: #64748b;
                opacity: 0.3;
                animation: ai-typing-pulse 1.1s infinite ease-in-out;
            }
            .ai-power-page .ai-typing span:nth-child(2) {
                animation-delay: 0.15s;
            }
            .ai-power-page .ai-typing span:nth-child(3) {
                animation-delay: 0.3s;
            }
            @keyframes ai-typing-pulse {
                0%, 80%, 100% {
                    opacity: 0.25;
                    transform: translateY(0);
                }
                40% {
                    opacity: 0.9;
                    transform: translateY(-3px);
                }
            }
        </style>
        @php
            $chatHistory = is_array($ai_power_chat ?? null) ? $ai_power_chat : [];
            if (count($chatHistory) === 0 && is_array(data_get($ai_power_result ?? [], 'conversation'))) {
                $chatHistory = (array) data_get($ai_power_result, 'conversation');
            }
            $chatHistory = array_slice($chatHistory, -24);
        @endphp
        <section class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm win-panel ai-shell-backdrop">
            <div class="relative p-5 lg:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.22em] text-slate-500">Natural Language Operations</p>
                        <h2 class="mt-1 text-2xl font-semibold text-slate-900">AI Power Command Console</h2>
                        <p class="mt-2 text-sm text-slate-600 max-w-3xl">
                            Tell the platform what to do in plain language. It translates instruction into safe actions, analytics, and policy operations.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-[11px]">
                        <span class="rounded-full border border-sky-300 bg-sky-100 px-2.5 py-1 font-semibold text-sky-800">Enter = Send</span>
                        <span class="rounded-full border border-slate-300 bg-white px-2.5 py-1 font-semibold text-slate-700">Shift+Enter = New Line</span>
                    </div>
                </div>

                <div class="mt-5">
                    <section class="ai-chat-shell rounded-2xl border border-slate-200 bg-white/95 backdrop-blur-sm shadow-sm overflow-hidden">
                        <div class="border-b border-slate-200 px-4 py-3 flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Conversation</p>
                            <p class="text-[11px] text-slate-500">AI asks follow-up questions if a request is unclear</p>
                        </div>
                        @error('ai_power')
                            <div class="mx-4 mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ $message }}</div>
                        @enderror
                        <div id="ai-power-chat-history" class="ai-chat-scroll ai-chat-history space-y-3 px-4 py-4">
                            @forelse($chatHistory as $entry)
                                @php
                                    $isUser = (string) ($entry['role'] ?? '') === 'user';
                                    $stamp = '';
                                    try {
                                        $stamp = \Illuminate\Support\Carbon::parse((string) ($entry['at'] ?? now()->toIso8601String()))->format('H:i');
                                    } catch (\Throwable) {
                                        $stamp = '';
                                    }
                                @endphp
                                <div class="ai-msg-row {{ $isUser ? 'justify-end' : 'justify-start' }}">
                                    @if(!$isUser)
                                        <span class="ai-msg-avatar border border-cyan-300 bg-cyan-100 text-cyan-800">AI</span>
                                    @endif
                                    <div class="ai-bubble {{ $isUser ? 'border-sky-300 bg-sky-100 text-sky-900' : 'border-slate-300 bg-white text-slate-800' }}">
                                        <p class="whitespace-pre-wrap break-words text-sm">{{ $entry['message'] ?? '' }}</p>
                                        @if($stamp !== '')
                                            <p class="mt-1 text-[10px] opacity-70">{{ $stamp }}</p>
                                        @endif
                                    </div>
                                    @if($isUser)
                                        <span class="ai-msg-avatar border border-sky-300 bg-sky-100 text-sky-800">YOU</span>
                                    @endif
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                                    Start a conversation. You can ask for actions, status, security, anomalies, compliance, reports, and recommendations.
                                </div>
                            @endforelse
                        </div>

                        <form method="POST" action="{{ route('admin.ai-power.execute') }}" class="ai-chat-composer-wrap border-t border-slate-200 bg-slate-50/95 px-4 py-3 space-y-3 backdrop-blur-sm">
                            @csrf
                            <input type="hidden" name="execute_now" value="1" />
                            <label for="instruction" class="block text-sm font-medium text-slate-700">Your Message</label>
                            <div class="ai-composer">
                                <textarea
                                    id="instruction"
                                    name="instruction"
                                    rows="3"
                                    class="ai-composer-input text-sm text-slate-800"
                                    placeholder="Type a command or question. Press Enter to send."
                                    required
                                ></textarea>
                                <button type="submit" class="ai-composer-send inline-flex items-center justify-center bg-skyline px-4 py-2 font-semibold text-white hover:brightness-110">
                                    Send
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </section>


    </div>
    <script>
        (function () {
            const input = document.getElementById('instruction');
            if (!input || !input.form) return;
            const form = input.form;
            const historyBox = document.getElementById('ai-power-chat-history');
            const submitButton = form.querySelector('button[type="submit"]');
            let submitting = false;
            if (historyBox) {
                historyBox.scrollTop = historyBox.scrollHeight;
            }

            function addTypingIndicator() {
                if (!historyBox || document.getElementById('ai-power-typing-row')) {
                    return;
                }

                const row = document.createElement('div');
                row.id = 'ai-power-typing-row';
                row.className = 'ai-msg-row justify-start';
                row.innerHTML = `
                    <span class="ai-msg-avatar border border-cyan-300 bg-cyan-100 text-cyan-800">AI</span>
                    <div class="ai-bubble border-slate-300 bg-white text-slate-700">
                        <div class="ai-typing" aria-label="AI is typing">
                            <span></span><span></span><span></span>
                        </div>
                        <p class="mt-1 text-[10px] opacity-70">AI is typing...</p>
                    </div>
                `;
                historyBox.appendChild(row);
                historyBox.scrollTop = historyBox.scrollHeight;
            }

            function lockComposer() {
                input.setAttribute('aria-busy', 'true');
                if (submitButton) {
                    if (!submitButton.dataset.originalText) {
                        submitButton.dataset.originalText = submitButton.textContent || 'Send';
                    }
                    submitButton.disabled = true;
                    submitButton.textContent = 'Thinking...';
                    submitButton.classList.add('opacity-70', 'cursor-wait');
                }

                window.setTimeout(function () {
                    input.value = '';
                    input.focus({ preventScroll: true });
                }, 0);
            }

            form.addEventListener('submit', function (event) {
                if (submitting) {
                    event.preventDefault();
                    return;
                }
                submitting = true;
                addTypingIndicator();
                lockComposer();
            });

            input.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' || event.shiftKey) {
                    return;
                }

                event.preventDefault();
                if (!submitting) {
                    form.requestSubmit();
                }
            });

        })();
    </script>
</x-admin-layout>
