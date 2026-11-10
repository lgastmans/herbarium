@extends('public.layouts.app')

@section('title', 'About')

@section('content')
    <div class="container page-content">
        <header class="page-heading"><p class="eyebrow">About the herbarium</p><h1>Plants, places<br>and preserved knowledge.</h1><p>Auroville Dry Herbarium · Auroville, Tamil Nadu, India</p></header>
        <div class="editorial-grid">
            <h2>A botanical record</h2>
            <div class="prose-copy"><p>Herbarium specimens preserve a record of plants at a particular place and time. Alongside each specimen, collection information helps connect the plant to its botanical name, locality, date and collectors.</p><p>This catalogue provides a way to explore Auroville Dry Herbarium’s collection records. A collection may include several physical specimens and photographs.</p><a class="text-link" href="{{ route('public.explore') }}">Explore the collections <span aria-hidden="true">→</span></a></div>
        </div>
    </div>
@endsection
