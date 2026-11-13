@extends('public.layouts.app')

@section('title', 'Contact')

@section('content')
    <div class="container page-content">
        <header class="page-heading"><p class="eyebrow">Contact</p><h1>Connect with<br>the herbarium.</h1><p>For questions about the collections and their botanical records.</p></header>
        <div class="editorial-grid">
            <div><h2>Auroville Dry Herbarium</h2><p class="location-line">Auroville, Tamil Nadu, India</p></div>
            <div class="prose-copy"><h2>Enquiring about a collection?</h2><p>Please include the collection number and botanical name in your enquiry so the relevant record can be identified.</p><a class="text-link" href="{{ route('public.explore') }}">Find a collection <span aria-hidden="true">→</span></a></div>
        </div>
    </div>
@endsection
