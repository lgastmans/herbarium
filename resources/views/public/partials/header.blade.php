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
            <a class="icon-button" href="{{ route('public.explore') }}#catalogue-search" aria-label="Search collections" aria-controls="header-search" data-search-toggle>@include('public.partials.icon', ['icon' => 'search'])</a>
        </nav>
        <form class="header-search" id="header-search" action="{{ route('public.explore') }}" method="get" role="search" aria-label="Search the collection" hidden>
            <div class="search-field">
                @include('public.partials.icon', ['icon' => 'search'])
                <label class="sr-only" for="header-search-input">Scientific name, family or collector</label>
                <input id="header-search-input" type="search" name="search" placeholder="Search the collection" maxlength="200" autocomplete="off">
            </div>
            <button class="button button--primary" type="submit">Search</button>
            <button class="icon-button" type="button" aria-label="Close search" data-search-close>@include('public.partials.icon', ['icon' => 'close'])</button>
        </form>
    </div>
</header>
