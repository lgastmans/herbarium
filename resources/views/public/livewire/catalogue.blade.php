@php
    $byFamily = $browse === 'family';
    $groups = [
        ['model' => 'family', 'label' => 'Family', 'options' => $filters['families']],
        ['model' => 'district', 'label' => 'Region', 'options' => $filters['districts']],
        ['model' => 'place', 'label' => 'Location', 'options' => $filters['places']],
        ['model' => 'collector', 'label' => 'Collector', 'options' => $filters['collectors']],
        ['model' => 'year', 'label' => 'Year collected', 'options' => $filters['years']],
    ];
@endphp
<div class="explore-layout">
    <aside class="filters" aria-label="Refine results">
        <h2>Refine results</h2>

        <section class="filter-section">
            <h3><label for="catalogue-search">Search</label></h3>
            <div class="search-field">
                @include('public.partials.icon', ['icon' => 'search'])
                <input id="catalogue-search" type="search" wire:model.live.debounce.300ms="search" maxlength="200"
                       placeholder="Search species, collectors or places">
            </div>
        </section>

        <nav class="filter-section" aria-label="Browse by">
            <h3>Browse by</h3>
            <div class="browse-options">
                <a href="{{ route('public.explore') }}" wire:click.prevent="browseBy('species')" @if (! $byFamily) aria-current="true" @endif>By species @include('public.partials.icon', ['icon' => 'chevron-right'])</a>
                <a href="{{ route('public.explore', ['browse' => 'family']) }}" wire:click.prevent="browseBy('family')" @if ($byFamily) aria-current="true" @endif>By family @include('public.partials.icon', ['icon' => 'chevron-right'])</a>
            </div>
        </nav>

        <section class="filter-section">
            <h3>Filter by</h3>
            @foreach ($groups as $group)
                <details class="filter-group" @if ($this->{$group['model']} !== '') open @endif>
                    <summary>{{ $group['label'] }}</summary>
                    <div class="filter-options">
                        <label class="sr-only" for="filter-{{ $group['model'] }}">{{ $group['label'] }}</label>
                        <select id="filter-{{ $group['model'] }}" wire:model.live="{{ $group['model'] }}">
                            <option value="">All</option>
                            @foreach ($group['options'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </details>
            @endforeach
            @if ($hasFilters)
                <button type="button" class="text-link clear-filters" wire:click="clearFilters">Clear all filters</button>
            @endif
        </section>

        <nav class="filter-section" aria-label="A to Z index">
            <h3>A–Z index</h3>
            <div class="alphabet">
                @foreach (range('A', 'Z') as $letter)
                    <a href="{{ route('public.explore', array_filter(['browse' => $byFamily ? 'family' : null, 'letter' => $letter])) }}"
                       wire:click.prevent="chooseLetter('{{ $letter }}')" @if ($this->letter === $letter) aria-current="true" @endif>{{ $letter }}</a>
                @endforeach
            </div>
        </nav>
    </aside>

    <section class="results" aria-labelledby="explore-title">
        <header class="results-heading">
            <h1 id="explore-title">Explore the collection</h1>
            <p>Search and browse pressed specimens across the subcontinent.</p>
        </header>

        <div class="sr-only" role="status" aria-live="polite">{{ number_format($specimens->total()) }} {{ \Illuminate\Support\Str::plural('collection', $specimens->total()) }} found</div>

        @if ($specimens->isEmpty())
            <p class="empty-message">No collections found. Try a different name or collection number, or clear the filters.</p>
        @else
            <div class="specimen-grid">
                @foreach ($specimens as $specimen)
                    <article class="specimen-card" wire:key="collection-{{ $specimen->id }}">
                        <a href="{{ route('public.specimen', $specimen->collection_number) }}">
                            @include('public.partials.collection-image', ['collection' => $specimen])
                            <h3 class="specimen-card__name"><span class="sr-only">Collection {{ $specimen->collection_number }}: </span><x-public.botanical-name :name="$specimen->genus?->name" /></h3>
                        </a>
                    </article>
                @endforeach
            </div>
        @endif

        <div class="results-toolbar">
            <label for="per-page">Results per page:</label>
            <select id="per-page" wire:model.live="perPage">
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <div class="catalogue-pagination">{{ $specimens->links() }}</div>
    </section>
</div>
