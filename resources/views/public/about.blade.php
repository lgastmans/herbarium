@extends('public.layouts.app')

@section('title', 'About')

@section('content')
    <div class="container">
        <section class="page-hero" aria-labelledby="about-title">
            <p class="eyebrow">About the herbarium</p>
            <h1 id="about-title">Plants, places<br>and preserved knowledge.</h1>
            <p class="page-hero__lead">Herbarium specimens preserve a record of plants at a particular place and time. This catalogue opens the Auroville Dry Herbarium’s collection records to anyone curious about them.</p>
            <p class="page-hero__meta">Auroville · Tamil Nadu, India</p>
        </section>

        <section class="page-section" aria-labelledby="record-title">
            <h2 class="section-title" id="record-title">What a record holds</h2>
            <p class="section-intro">Alongside each pressed specimen, the collection information connects the plant to the context in which it was found.</p>
            <div class="info-grid">
                <article class="info-card">@include('public.partials.icon', ['icon' => 'leaf'])<h3>Botanical name</h3><p>The genus and species, with its plant family, as determined for the specimen.</p></article>
                <article class="info-card">@include('public.partials.icon', ['icon' => 'pin'])<h3>Locality</h3><p>The place where the plant was gathered, so its distribution can be traced.</p></article>
                <article class="info-card">@include('public.partials.icon', ['icon' => 'calendar'])<h3>Collection date</h3><p>When the specimen was collected, fixing the plant in a particular season and year.</p></article>
                <article class="info-card">@include('public.partials.icon', ['icon' => 'person'])<h3>Collectors</h3><p>The people who collected the specimen and recorded its field notes.</p></article>
            </div>
            <p class="section-note">A collection may include several physical specimens and photographs, gathered together on a single record.</p>
        </section>
    </div>

    <section class="discovery-band" aria-labelledby="about-explore-title">
        <div class="container">
            <h2 id="about-explore-title">Start exploring</h2>
            <p>Three ways into the collection.</p>
            <div class="discovery-grid">
                <a class="discovery-card" href="{{ route('public.explore') }}">@include('public.partials.icon', ['icon' => 'leaf'])<div><h3>Species A–Z</h3><p>Browse every species</p></div><span class="arrow" aria-hidden="true">→</span></a>
                <a class="discovery-card" href="{{ route('public.explore') }}">@include('public.partials.icon', ['icon' => 'flower'])<div><h3>Plant families</h3><p>Explore by botanical family</p></div><span class="arrow" aria-hidden="true">→</span></a>
                <a class="discovery-card" href="{{ route('public.contact') }}">@include('public.partials.icon', ['icon' => 'person'])<div><h3>Ask a question</h3><p>Get in touch with the herbarium</p></div><span class="arrow" aria-hidden="true">→</span></a>
            </div>
        </div>
    </section>
@endsection
