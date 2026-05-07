@props([
    'id' => null,
    'label' => 'Theme',
    'wrapperClass' => '',
    'labelClass' => '',
    'selectClass' => '',
])

<div
    class="{{ trim('theme-select-shell '.$wrapperClass) }}"
    @if($id) id="{{ $id }}" @endif
    aria-label="{{ $label !== '' ? $label : 'Theme' }}"
>
    @if($label !== '')
        <span class="sr-only {{ trim($labelClass) }}">
            {{ $label }}
        </span>
    @endif

    <button
        type="button"
        data-theme-toggle
        class="theme-toggle-btn {{ trim($selectClass) }}"
        aria-label="Toggle theme"
        title="Toggle theme"
        data-theme-current="light"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="theme-icon theme-icon-light h-4 w-4" aria-hidden="true">
            <circle cx="12" cy="12" r="4"></circle>
            <path d="M12 2v2.5"></path>
            <path d="M12 19.5V22"></path>
            <path d="M4.93 4.93 6.7 6.7"></path>
            <path d="M17.3 17.3 19.07 19.07"></path>
            <path d="M2 12h2.5"></path>
            <path d="M19.5 12H22"></path>
            <path d="M4.93 19.07 6.7 17.3"></path>
            <path d="M17.3 6.7 19.07 4.93"></path>
        </svg>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="theme-icon theme-icon-dark h-4 w-4" aria-hidden="true">
            <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"></path>
        </svg>
        <span class="sr-only">Toggle dark and light theme</span>
    </button>
</div>
