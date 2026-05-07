@php
    $eyebrow = $eyebrow ?? 'Endpoint Intelligence';
    $title = $title ?? '';
    $description = $description ?? null;
    $badges = is_array($badges ?? null) ? $badges : [];
    $actions = is_array($actions ?? null) ? $actions : [];
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div class="max-w-3xl">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">{{ $eyebrow }}</p>
            <h2 class="mt-2 text-2xl font-semibold text-slate-900">{{ $title }}</h2>
            @if ($description)
                <p class="mt-2 text-sm text-slate-600">{{ $description }}</p>
            @endif

            @if ($badges !== [])
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($badges as $badge)
                        <span class="{{ $badge['class'] ?? 'ei-chip' }} px-3 py-1 text-xs font-medium">
                            {{ $badge['label'] ?? '' }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($actions !== [])
            <div class="grid gap-2 sm:grid-cols-2 xl:w-[24rem]">
                @foreach ($actions as $action)
                    <a href="{{ $action['href'] ?? '#' }}" class="{{ $action['class'] ?? 'rounded-xl border border-slate-300 px-4 py-3 text-sm font-medium text-slate-700' }}">
                        {{ $action['label'] ?? '' }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</section>
