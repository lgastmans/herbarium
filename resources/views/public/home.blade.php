@extends('public.layouts.app')

@section('page-class', 'page-home')

@section('content')
    <div class="container">
        <section class="hero" aria-labelledby="home-title">
            <div class="hero-copy">
                <h1 id="home-title">Explore the Auroville<br>Dry Herbarium<br>collection</h1>
                <p class="hero-description">Pressed specimens, original field notes and collection records, open for research and discovery.</p>
                <form class="search-form" action="{{ route('public.explore') }}" method="get" role="search" aria-label="Search the collection">
                    <div class="search-field">
                        @include('public.partials.icon', ['icon' => 'search'])
                        <label class="sr-only" for="home-search">Scientific name, family or collector</label>
                        <input id="home-search" type="search" name="search" placeholder="Search scientific name, family or collector" maxlength="200">
                    </div>
                    <button class="button button--primary" type="submit">Search</button>
                </form>
            </div>
            <div class="hero-specimen">
                @include('public.partials.collection-image', ['collection' => $featuredCollections->first(), 'eager' => true, 'imageClass' => 'hero-image'])
            </div>
        </section>

        <section class="home-section" aria-labelledby="featured-title">
            <h2 class="section-title" id="featured-title">From the collection</h2>
            <div class="featured-grid">
                @forelse ($featuredCollections as $collection)
                    <article class="specimen-card">
                        <a href="{{ route('public.specimen', $collection->collection_number) }}">
                            @include('public.partials.collection-image', ['collection' => $collection])
                            <h3 class="specimen-card__name"><span class="sr-only">Collection {{ $collection->collection_number }}: </span><x-public.botanical-name :name="$collection->genus?->name" /></h3>
                        </a>
                        <dl class="card-metadata">
                            <div><dt>@include('public.partials.icon', ['icon' => 'leaf'])<span class="sr-only">Family</span></dt><dd>{{ $collection->family?->family ?: 'Not recorded' }}</dd></div>
                            <div><dt>@include('public.partials.icon', ['icon' => 'pin'])<span class="sr-only">Location</span></dt><dd>{{ $collection->place?->name ?: 'Not recorded' }}</dd></div>
                            <div><dt>@include('public.partials.icon', ['icon' => 'calendar'])<span class="sr-only">Collected</span></dt><dd>{{ $collection->collected_on ? \Carbon\Carbon::parse($collection->collected_on)->format('d M Y') : 'Not recorded' }}</dd></div>
                            @php
                                $collectorNames = collect([$collection->collector1_id, $collection->collector2_id, $collection->collector3_id, $collection->collector4_id])
                                    ->filter()->unique()->map(fn ($id) => $collectors->get($id)?->display_collector)->filter()->implode('; ');
                            @endphp
                            <div><dt>@include('public.partials.icon', ['icon' => 'person'])<span class="sr-only">Collectors</span></dt><dd>{{ $collectorNames ?: 'Not recorded' }}</dd></div>
                        </dl>
                    </article>
                @empty
                    <p class="empty-message">No collection previews are available.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="discovery-band" aria-labelledby="study-title">
        <div class="container">
            <h2 id="study-title">A collection for study and discovery</h2>
            <p>Explore preserved specimens alongside their collection dates, localities and collectors.<br>Each record brings the specimen sheet and its field information together.</p>
            <div class="discovery-grid">
                <a class="discovery-card" href="{{ route('public.explore') }}">@include('public.partials.icon', ['icon' => 'leaf'])<div><h3>Species A–Z</h3><p>Browse all species<br>in the collection</p></div><span class="arrow" aria-hidden="true">→</span></a>
                <a class="discovery-card" href="{{ route('public.explore', ['browse' => 'family']) }}">@include('public.partials.icon', ['icon' => 'flower'])<div><h3>Plant families</h3><p>Explore by botanical<br>family</p></div><span class="arrow" aria-hidden="true">→</span></a>
                <a class="discovery-card" href="{{ route('public.explore') }}#catalogue-search">@include('public.partials.icon', ['icon' => 'pin'])<div><h3>Collection localities</h3><p>View specimens by<br>place of collection</p></div><span class="arrow" aria-hidden="true">→</span></a>
            </div>
        </div>
    </section>

    <section class="container home-section" aria-labelledby="localities-title">
        <div class="section-header"><h2 class="section-title" id="localities-title">Collection localities</h2><a class="text-link" href="{{ route('public.explore') }}">View all localities <span class="arrow" aria-hidden="true">→</span></a></div>
        <p class="localities-intro">Browse specimens by the places where they were collected.</p>
        <div class="locality-grid">
            @foreach ($localities as $locality)
                <a class="locality-card" href="{{ route('public.explore', ['place' => $locality->id]) }}"><div class="locality-visual" aria-hidden="true">@include('public.partials.icon', ['icon' => 'pin'])</div><div class="locality-card__caption"><span>{{ $locality->name }}</span><span class="arrow" aria-hidden="true">→</span></div></a>
            @endforeach
        </div>
    </section>
@endsection
