@props([
    'icon' => 'insights',
    'eyebrow' => 'Endpoint Intelligence',
    'title',
    'description' => null,
    'highlights' => [],
])

@php
    $iconClasses = 'h-7 w-7';
@endphp

@include('admin.endpoint-intelligence.partials.theme')

<section class="endpoint-intelligence-header mb-5 overflow-hidden p-6 text-slate-900">
    <div class="endpoint-intelligence-header__layout">
        <div class="endpoint-intelligence-header__icon">
                @switch($icon)
                    @case('health')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M4 12h3l2-5 4 10 2-5h5" />
                            <path d="M12 21c5-3.2 8-6.3 8-10.4A4.6 4.6 0 0 0 12 7a4.6 4.6 0 0 0-8 3.6C4 14.7 7 17.8 12 21Z" />
                        </svg>
                        @break
                    @case('risk')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M12 3 5 6v5c0 5 3.2 8.5 7 10 3.8-1.5 7-5 7-10V6l-7-3Z" />
                            <path d="M12 8v4" />
                            <circle cx="12" cy="15.5" r="0.9" fill="currentColor" stroke="none" />
                        </svg>
                        @break
                    @case('incident')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <circle cx="6" cy="6" r="2.5" />
                            <circle cx="18" cy="7" r="2.5" />
                            <circle cx="12" cy="18" r="2.5" />
                            <path d="M8.2 7.2 15.6 17" />
                            <path d="M15.7 8.8 12.9 15.6" />
                        </svg>
                        @break
                    @case('assistant')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M12 3 9.9 8.4 4 10.5l5.9 2.1L12 18l2.1-5.4 5.9-2.1-5.9-2.1L12 3Z" />
                            <path d="M5 3v3" />
                            <path d="M19 18v3" />
                            <path d="M3 5h3" />
                            <path d="M18 19h3" />
                        </svg>
                        @break
                    @case('remediation')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="m14.5 5.5 4 4" />
                            <path d="M6.8 17.2 17 7a2.8 2.8 0 1 0-4-4L2.8 13.2a2 2 0 0 0-.5 1L2 20l5.8-.3a2 2 0 0 0 1-.5Z" />
                            <path d="m12 8 4 4" />
                        </svg>
                        @break
                    @case('approval')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M12 3 5 6v6c0 4.7 3 7.9 7 9 4-1.1 7-4.3 7-9V6l-7-3Z" />
                            <path d="m9 12 2 2 4-4" />
                        </svg>
                        @break
                    @case('history')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M3 12a9 9 0 1 0 2.6-6.4" />
                            <path d="M3 4v5h5" />
                            <path d="M12 7v5l3 2" />
                        </svg>
                        @break
                    @case('autonomy')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M4 7h16" />
                            <path d="M4 17h16" />
                            <path d="M7 7v10" />
                            <path d="M17 7v10" />
                            <circle cx="7" cy="11" r="2.5" />
                            <circle cx="17" cy="13" r="2.5" />
                        </svg>
                        @break
                    @case('tuning')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M4 6h7" />
                            <path d="M13 6h7" />
                            <path d="M4 18h11" />
                            <path d="M17 18h3" />
                            <path d="M9 3v6" />
                            <path d="M15 15v6" />
                            <circle cx="12" cy="6" r="1.8" />
                            <circle cx="16" cy="18" r="1.8" />
                        </svg>
                        @break
                    @case('summary')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" />
                            <path d="M14 3v5h5" />
                            <path d="M9 12h6" />
                            <path d="M9 16h6" />
                        </svg>
                        @break
                    @default
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="{{ $iconClasses }}">
                            <path d="M4 6h16" />
                            <path d="M4 12h16" />
                            <path d="M4 18h16" />
                        </svg>
                @endswitch
        </div>
        <div class="endpoint-intelligence-header__body">
            <div class="endpoint-intelligence-header__copy">
                <p class="endpoint-intelligence-header__eyebrow">{{ $eyebrow }}</p>
                <h1 class="endpoint-intelligence-header__title">{{ $title }}</h1>
                @if ($description)
                    <p class="endpoint-intelligence-header__description">{{ $description }}</p>
                @endif
            </div>

            @if (!empty($highlights))
                <div class="endpoint-intelligence-header__highlights">
                    @foreach ($highlights as $highlight)
                        <div class="endpoint-intelligence-header__highlight">
                            <div class="endpoint-intelligence-header__highlight-label">{{ $highlight['label'] ?? 'Focus' }}</div>
                            <div class="endpoint-intelligence-header__highlight-value">{{ $highlight['value'] ?? '' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="endpoint-intelligence-header__meta">
                <span class="endpoint-intelligence-header__meta-dot" aria-hidden="true"></span>
                <span>Safe telemetry, grounded reasoning, controlled action</span>
            </div>
        </div>
    </div>
</section>
