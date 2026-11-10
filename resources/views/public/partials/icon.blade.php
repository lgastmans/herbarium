<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($icon)
        @case('search')
            <circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 6 6"/>
            @break
        @case('pin')
            <path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="1"/><path d="M7 2v6m10-6v6M3 11h18m-13 4h1m5 0h1m-7 3h1m5 0h1"/>
            @break
        @case('person')
            <circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>
            @break
        @case('leaf')
            <path d="M6 22 17 3M12 13C4 14 2 8 3 5c6 0 10 3 9 8Zm1-3c-1-6 3-9 8-9 0 5-2 8-8 9ZM8 19c-5 1-7-2-7-5 4-1 7 1 7 5Zm2-3c1-5 5-6 10-4-2 5-5 7-10 4Z"/>
            @break
        @case('image')
            <rect x="3" y="2" width="18" height="20"/><path d="m3 17 6-6 5 5 3-3 4 4"/><circle cx="16" cy="7" r="2"/>
            @break
        @case('flower')
            <circle cx="12" cy="12" r="2.5"/><path d="M12 9.5C9 6 10 2 12 2s3 4 0 7.500Zm2.500 2.500c3.500-3 7.500-2 7.500 0s-4 3-7.500 0Zm-2.500 2.500c3 3.500 2 7.500 0 7.500s-3-4 0-7.500ZM9.500 12C6 15 2 14 2 12s4-3 7.500 0Z"/>
            @break
        @case('chevron-right')
            <path d="m9 6 6 6-6 6"/>
            @break
        @case('chevron-left')
            <path d="m15 6-6 6 6 6"/>
            @break
        @case('arrow-left')
            <path d="M20 12H4m6-6-6 6 6 6"/>
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break
        @case('minus')
            <path d="M5 12h14"/>
            @break
        @case('download')
            <path d="M12 3v12m-5-5 5 5 5-5M4 21h16"/>
            @break
        @case('fullscreen')
            <path d="M3 9V3h6M21 9V3h-6M3 15v6h6M21 15v6h-6"/>
            @break
        @default
            <path d="M4 12h16m-6-6 6 6-6 6"/>
    @endswitch
</svg>
