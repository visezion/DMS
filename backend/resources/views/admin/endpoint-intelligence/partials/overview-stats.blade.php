@php
    $cards = is_array($cards ?? null) ? $cards : [];
    $gridClass = $gridClass ?? 'xl:grid-cols-4';
@endphp

<section class="grid gap-3 md:grid-cols-2 {{ $gridClass }}">
    @foreach ($cards as $card)
        <article class="{{ $card['class'] ?? 'rounded-2xl border border-slate-200 bg-white p-4 shadow-sm' }}">
            <p class="{{ $card['label_class'] ?? 'text-xs uppercase tracking-[0.18em] text-slate-500' }}">{{ $card['label'] ?? '' }}</p>
            <p class="{{ $card['value_class'] ?? 'mt-2 text-3xl font-semibold text-slate-900' }}">{{ $card['value'] ?? '' }}</p>
            @if (!empty($card['description']))
                <p class="{{ $card['description_class'] ?? 'mt-1 text-sm text-slate-500' }}">{{ $card['description'] }}</p>
            @endif
        </article>
    @endforeach
</section>
