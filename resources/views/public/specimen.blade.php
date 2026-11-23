@extends('public.layouts.app')

@php
    $dateFormat = fn ($date) => $date ? \Carbon\Carbon::parse($date)->format('d M Y') : null;
    $image = $images->first();
    $name = $specimen->genus?->name ?: 'Botanical name not recorded';
    // A value counts as "nothing" when blank or a known import placeholder (e.g. "name1", "-", "N/A").
    $placeholders = ['name1', '-', '--', 'n/a', 'na', 'nil', 'null'];
    $present = fn ($value) => is_scalar($value) && filled($value) && ! in_array(strtolower(trim((string) $value)), $placeholders, true);
    $fields = fn (array $rows) => array_filter($rows, $present);
    // Link to the Explore page with the matching filter applied.
    $explore = fn (string $filter, $value) => route('public.explore', [$filter => $value]);
    $year = $specimen->collected_on ? \Carbon\Carbon::parse($specimen->collected_on)->format('Y') : null;
    $links = [
        'Place' => $specimen->place_id ? $explore('place', $specimen->place_id) : null,
        'District' => $specimen->district_id && $present($specimen->district?->name) ? $explore('district', $specimen->district_id) : null,
    ];

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
                    <div class="image-frame">
                        <div class="image-viewer__stage" data-viewer-stage>
                            @if ($image)
                                <img src="{{ $image->url }}" alt="Herbarium sheet of {{ strip_tags($name) }}, collection {{ $specimen->collection_number }}" data-viewer-image width="600" height="800">
                            @else
                                @include('public.partials.collection-image', ['collection' => $specimen, 'imageClass' => 'specimen-card__image'])
                            @endif
                        </div>
                        @if ($image)
                            <div class="image-controls">
                                <button class="image-control" type="button" hidden aria-pressed="false" aria-label="Zoom" title="Zoom (click the sheet, then drag to pan)" data-viewer-zoom>@include('public.partials.icon', ['icon' => 'search'])</button>
                                <button class="image-control" type="button" hidden aria-label="Fullscreen" title="Fullscreen" data-fullscreen>@include('public.partials.icon', ['icon' => 'fullscreen'])</button>
                                <a class="image-control" href="{{ $image->url }}" download aria-label="Download image" title="Download image" data-viewer-download>@include('public.partials.icon', ['icon' => 'download'])</a>
                            </div>
                            <p class="image-hint" aria-hidden="true">Hover to magnify · click to zoom and drag</p>
                        @endif
                    </div>
                    @if ($image)
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
                                <span><small>{{ $related->collection_number }}</small><x-public.botanical-name :name="$related->genus?->name" /></span>
                            </a>
                        @endforeach
                    </div>
                </section>
            </div>

            <article class="record-content">
                <p class="record-eyebrow">Collection {{ $specimen->collection_number }}</p>
                <h1 class="record-title"><x-public.botanical-name :name="$specimen->genus?->name" /></h1>
                @if ($present($specimen->vernacular_name))
                    <p class="vernacular">{{ $specimen->vernacular_name }}</p>
                @endif

                <dl class="record-summary">
                    @foreach ([
                        ['leaf', 'Family', $specimen->family?->family, $specimen->family_id ? $explore('family', $specimen->family_id) : null],
                        ['pin', 'Location', $specimen->place?->name, $links['Place']],
                        ['calendar', 'Collected', $dateFormat($specimen->collected_on), $year ? $explore('year', $year) : null],
                        ['person', 'Collectors', $collectors, null],
                    ] as [$icon, $label, $value, $href])
                        @if (is_countable($value) ? count($value) > 0 : $present($value))
                            <div class="summary-row">@include('public.partials.icon', ['icon' => $icon])<dt>{{ $label }}</dt><dd>
                                @if ($label === 'Collectors')
                                    @foreach ($value as $id => $collectorName)
                                        <a class="text-link" href="{{ $explore('collector', $id) }}">{{ $collectorName }}</a>@if (! $loop->last); @endif
                                    @endforeach
                                @elseif ($href)
                                    <a class="text-link" href="{{ $href }}">{{ $value }}</a>
                                @else
                                    {{ $value }}
                                @endif
                            </dd></div>
                        @endif
                    @endforeach
                </dl>

                @foreach ($sections as $title => $rows)
                    @if ($rows)
                        <section class="record-section" aria-labelledby="section-{{ \Illuminate\Support\Str::slug($title) }}">
                            <h2 id="section-{{ \Illuminate\Support\Str::slug($title) }}">{{ $title }}</h2>
                            <dl class="record-fields">
                                @foreach ($rows as $label => $value)
                                    <dt>{{ $label }}</dt><dd>
                                        @if (! empty($links[$label]) && $title === 'Location')
                                            <a class="text-link" href="{{ $links[$label] }}">{{ $value }}</a>
                                        @else
                                            {!! nl2br(e($value)) !!}
                                        @endif
                                    </dd>
                                @endforeach
                            </dl>
                        </section>
                    @endif
                @endforeach
            </article>
        </div>
    </div>
@endsection
