@php
    $items = [
        ['route' => 'admin.intelligence.autonomous.decisions', 'label' => 'Decisions'],
        ['route' => 'admin.intelligence.autonomous.policies', 'label' => 'Policies'],
        ['route' => 'admin.intelligence.autonomous.mappings', 'label' => 'Mappings'],
        ['route' => 'admin.intelligence.autonomous.catalog', 'label' => 'Catalog'],
        ['route' => 'admin.intelligence.autonomous.simulate', 'label' => 'Simulation'],
    ];
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Autonomous Response</p>
            <h2 class="text-xl font-semibold text-slate-900">{{ $title ?? 'Autonomous Response' }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ $description ?? 'AI-powered autonomous response for endpoints.' }}</p>
        </div>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach($items as $item)
            <a
                href="{{ route($item['route']) }}"
                class="rounded-lg border px-3 py-1.5 text-sm font-medium {{ request()->routeIs($item['route'].'*') ? 'border-skyline bg-skyline text-white' : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-white' }}"
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</section>
