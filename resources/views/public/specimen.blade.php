@extends('public.layouts.app')

@php
    $dateFormat = fn ($date) => $date ? \Carbon\Carbon::parse($date)->format('d M Y') : null;
    $image = $images->first();
    $name = $specimen->genus?->name ?: 'Botanical name not recorded';
    $fields = fn (array $rows) => array_filter($rows, fn ($value) => filled($value));

    $details = $fields([
        'Collection number' => $specimen->collection_number,
        'Accession number' => $specimen->herbarium_number,
        'Herbarium' => 'AURO',
        'Habit' => $specimen->habit,
        'Micro habitat' => $specimen->micro_habitat,
        'Forest type' => $specimen->forest,
        'Frequency' => $specimen->frequency,
        'Phenology' => $specimen->phenology,
    ]);
    $location = $fields([
        'Place' => $specimen->place?->name,
        'Taluk' => $specimen->taluk?->name,
        'District' => $specimen->district?->name,
        'State' => $specimen->state?->name,
        'Latitude' => $specimen->latitude,
        'Longitude' => $specimen->longitude,
        'Altitude' => $specimen->altitude,
    ]);
    $description = $fields([
        'Description' => $specimen->description,
        'Leaf' => $specimen->leaf,
        'Flower' => $specimen->flower,
        'Fruit' => $specimen->fruit,
        'Seeds' => $specimen->seeds,
    ]);
    $observations = $fields([
        'Association' => $specimen->association,
        'Notes' => $specimen->notes,
    ]);
    $sections = ['Collection details' => $details, 'Location' => $location, 'Description' => $description, 'Field observations' => $observations];
@endphp

@section('title', strip_tags($name).' · Collection '.$specimen->collection_number)
@section('page-class', 'page-specimen')
@section('description', 'Collection '.$specimen->collection_number.' of Auroville Dry Herbarium: '.strip_tags($name).'.')

@section('content')
    <div class="container">
        <nav class="record-navigation" aria-label="Record navigation">
            <a href="{{ $backUrl }}">@include('public.partials.icon', ['icon' => 'arrow-left']) Back to results</a>
            <div class="record-pagination">
                @if ($previous)<a href="{{ route('public.specimen', $previous->collection_number) }}" rel="prev">@include('public.partials.icon', ['icon' => 'chevron-left']) Previous</a>@endif
                @if ($next)<a href="{{ route('public.specimen', $next->collection_number) }}" rel="next">Next @include('public.partials.icon', ['icon' => 'chevron-right'])</a>@endif
            </div>
        </nav>

        <div class="record-layout">
            <div class="record-media">
                <div class="image-viewer" data-image-viewer>
                    <div class="image-viewer__stage">
                        @if ($image)
                            <img src="{{ $image->url }}" alt="Herbarium sheet of {{ strip_tags($name) }}, collection {{ $specimen->collection_number }}" data-viewer-image width="600" height="800">
                        @else
                            @include('public.partials.collection-image', ['collection' => $specimen, 'imageClass' => 'specimen-card__image'])
                        @endif
                    </div>
                    @if ($image)
                        <div class="image-toolbar">
                            <div class="zoom-controls" role="group" aria-label="Zoom" hidden data-viewer-zoom>
                                <button type="button" data-zoom="out" aria-label="Zoom out">@include('public.partials.icon', ['icon' => 'minus'])</button>
                                <button type="button" data-zoom="fit">Fit</button>
                                <button type="button" data-zoom="in" aria-label="Zoom in">@include('public.partials.icon', ['icon' => 'plus'])</button>
                            </div>
                            <a class="button" href="{{ $image->url }}" download data-viewer-download>@include('public.partials.icon', ['icon' => 'download']) Download image</a>
                            <button class="button" type="button" hidden data-fullscreen>@include('public.partials.icon', ['icon' => 'fullscreen']) Fullscreen</button>
                        </div>
                        @if ($images->count() > 1)
                            <ul class="image-thumbs" aria-label="Images of this collection">
                                @foreach ($images as $thumb)
                                    <li><a href="{{ $thumb->url }}" data-viewer-thumb @if ($loop->first) aria-current="true" @endif><img src="{{ $thumb->url }}" alt="Image {{ $loop->iteration }} of {{ $images->count() }}" loading="lazy" width="60" height="80"></a></li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </div>

                <section class="continue-browsing" aria-labelledby="continue-title">
                    <h2 id="continue-title">Continue browsing</h2>
                    <div class="related-strip">
                        @foreach ($strip as $related)
                            <a class="related-card" href="{{ route('public.specimen', $related->collection_number) }}" @if ($related->is($specimen)) aria-current="true" @endif>
                                @include('public.partials.collection-image', ['collection' => $related, 'imageClass' => 'related-card__image'])
                                <span><small>Collection {{ $related->collection_number }}</small><x-public.botanical-name :name="$related->genus?->name" /></span>
                            </a>
                        @endforeach
                    </div>
                </section>
            </div>

            <article class="record-content">
                <p class="record-eyebrow">Collection {{ $specimen->collection_number }}</p>
                <h1 class="record-title"><x-public.botanical-name :name="$specimen->genus?->name" /></h1>
                @if (filled($specimen->vernacular_name))
                    <p class="vernacular">{{ $specimen->vernacular_name }}</p>
                @endif

                <dl class="record-summary">
                    @foreach ([
                        ['leaf', 'Family', $specimen->family?->family],
                        ['pin', 'Location', $specimen->place?->name],
                        ['calendar', 'Collected', $dateFormat($specimen->collected_on)],
                        ['person', 'Collectors', $collectors],
                    ] as [$icon, $label, $value])
                        @if (filled($value))
                            <div class="summary-row">@include('public.partials.icon', ['icon' => $icon])<dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                        @endif
                    @endforeach
                </dl>

                @foreach ($sections as $title => $rows)
                    @if ($rows)
                        <section class="record-section" aria-labelledby="section-{{ \Illuminate\Support\Str::slug($title) }}">
                            <h2 id="section-{{ \Illuminate\Support\Str::slug($title) }}">{{ $title }}</h2>
                            <dl class="record-fields">
                                @foreach ($rows as $label => $value)
                                    <dt>{{ $label }}</dt><dd>{!! nl2br(e($value)) !!}</dd>
                                @endforeach
                            </dl>
                        </section>
                    @endif
                @endforeach
            </article>
        </div>
    </div>
@endsection
