@extends('public.layouts.app')

@section('title', 'Contact')

@section('content')
    <div class="container">
        <section class="page-hero" aria-labelledby="contact-title">
            <p class="eyebrow">Contact</p>
            <h1 id="contact-title">Connect with<br>the herbarium.</h1>
            <p class="page-hero__lead">For questions about the collections and their botanical records.</p>
            <p class="page-hero__meta">Auroville · Tamil Nadu, India</p>
        </section>

        <div class="contact-layout">
            <section class="contact-card" aria-labelledby="contact-where">
                @include('public.partials.icon', ['icon' => 'pin'])
                <h2 id="contact-where">Auroville Dry Herbarium</h2>
                <address class="contact-address">Auroville<br>Tamil Nadu, India</address>
                @if (config('herbarium.contact_email'))
                    <a class="text-link contact-email" href="mailto:{{ config('herbarium.contact_email') }}">{{ config('herbarium.contact_email') }}</a>
                @endif
            </section>

            <section class="contact-main" aria-labelledby="contact-enquiry">
                <h2 id="contact-enquiry">Enquiring about a collection?</h2>
                <p class="section-intro">A few details help the relevant record be found quickly.</p>
                <ol class="steps">
                    <li><span class="steps__num" aria-hidden="true">1</span><div><h3>Find the record</h3><p>Look up the plant in the catalogue and note its collection number.</p></div></li>
                    <li><span class="steps__num" aria-hidden="true">2</span><div><h3>Include the details</h3><p>Give the collection number and botanical name in your message.</p></div></li>
                    <li><span class="steps__num" aria-hidden="true">3</span><div><h3>Say what you need</h3><p>Image access, locality details or a visit to the collection.</p></div></li>
                </ol>
                <form class="search-form contact-lookup" action="{{ route('public.explore') }}" method="get" role="search" aria-label="Find a collection">
                    <div class="search-field">
                        @include('public.partials.icon', ['icon' => 'search'])
                        <label class="sr-only" for="contact-search">Scientific name, family or collector</label>
                        <input id="contact-search" type="search" name="search" placeholder="Search scientific name, family or collector" maxlength="200">
                    </div>
                    <button class="button button--primary" type="submit">Find a collection</button>
                </form>
            </section>
        </div>
    </div>
@endsection
