@php
    $navigation = [
        ['route' => 'public.home', 'label' => 'Home'],
        ['route' => 'public.explore', 'label' => 'Explore'],
        ['route' => 'public.about', 'label' => 'About'],
        ['route' => 'public.contact', 'label' => 'Contact'],
    ];
@endphp
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="{{ route('public.home') }}" aria-label="Auroville Dry Herbarium home">
            Auroville Dry Herbarium
        </a>
        <nav class="site-nav" aria-label="Main navigation">
            @foreach ($navigation as $item)
                <a href="{{ route($item['route']) }}" @if(request()->routeIs($item['route'])) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
            <a class="icon-button" href="{{ route('public.explore') }}#catalogue-search" aria-label="Search collections">@include('public.partials.icon', ['icon' => 'search'])</a>
        </nav>
    </div>
</header>
