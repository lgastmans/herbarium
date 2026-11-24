@php
    $groups = [
        ['model' => 'species', 'label' => 'Species', 'options' => []],
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
                       placeholder="Search collection">
            </div>
        </section>

        <section class="filter-section">
            <h3>Filter by</h3>
            @foreach ($groups as $group)
                @if ($group['model'] === 'species')
            <details class="filter-group" wire:ignore.self @if ($species !== '') open @endif>
                <summary>Species</summary>
                <div class="filter-options">
                    <label class="sr-only" for="filter-species">Species</label>
                    <div class="combobox" wire:ignore x-data="{
                                id: $wire.entangle('species').live,
                                label: @js($speciesLabel),
                                text: @js($speciesLabel),
                                items: [],
                                open: false,
                                active: 0,
                                timer: null,
                                search() {
                                    clearTimeout(this.timer);
                                    const term = this.text.trim();
                                    if (term === '') { this.items = []; return; }
                                    this.timer = setTimeout(async () => { this.items = await $wire.suggestSpecies(term); this.active = 0; }, 200);
                                },
                                choose(item) { this.id = item.id; this.label = item.name; this.text = item.name; this.items = []; this.open = false; },
                                clear() { this.id = ''; this.label = ''; this.text = ''; this.items = []; this.active = 0; this.$refs.input.focus(); },
                                move(step) {
                                    const count = this.items.length;
                                    if (count) { this.open = true; this.active = (this.active + step + count) % count; this.$nextTick(() => this.$refs.list.children[this.active]?.scrollIntoView({ block: 'nearest' })); }
                                },
                                pick() { if (this.open && this.items[this.active]) { this.choose(this.items[this.active]); } },
                            }" x-effect="if (id === '' && label !== '') { label = ''; text = ''; }"
                             x-on:click.outside="open = false; text = label">
                            <input id="filter-species" x-ref="input" type="text" role="combobox" autocomplete="off" aria-autocomplete="list"
                                   aria-controls="list-species" x-bind:aria-expanded="open && items.length > 0" placeholder="Type a species name"
                                   x-model="text"
                                   x-on:focus="$el.select()"
                                   x-on:input="open = true; search(); if (text.trim() === '') { id = ''; label = ''; }"
                                   x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
                                   x-on:keydown.enter.prevent="pick()" x-on:keydown.escape="open = false; text = label">
                            <button type="button" class="combobox__clear" x-show="id !== '' || text !== ''" x-cloak x-on:click="clear()" aria-label="Clear species filter">&times;</button>
                            <ul id="list-species" class="combobox__list" role="listbox" x-ref="list" x-show="open && items.length" x-cloak>
                                <template x-for="(item, index) in items" x-bind:key="item.id">
                                    <li role="option" x-bind:aria-selected="item.id == id" x-bind:class="{ 'is-active': index === active, 'is-selected': item.id == id }"
                                        x-text="item.name" x-on:mousedown.prevent="choose(item)" x-on:mousemove="active = index"></li>
                                </template>
                            </ul>
                        </div>
                </div>
            </details>
                @else
                <details class="filter-group" wire:ignore.self @if ($this->{$group['model']} !== '') open @endif>
                    <summary>{{ $group['label'] }}</summary>
                    <div class="filter-options">
                        <div class="combobox" wire:ignore x-data="{
                                id: $wire.entangle('{{ $group['model'] }}').live,
                                options: @js(collect($group['options'])->map(fn ($name, $id) => [(string) $id, (string) $name])->values()),
                                text: '',
                                open: false,
                                active: 0,
                                label() { return this.options.find(([key]) => key === String(this.id))?.[1] ?? '' },
                                get matches() {
                                    const needle = this.text.trim().toLowerCase();
                                    const all = this.options;
                                    const shown = this.text === this.label() || needle === '' ? all : all.filter(([, name]) => String(name).toLowerCase().includes(needle));
                                    return shown.slice(0, 100);
                                },
                                choose(key) { this.id = key; this.text = this.label(); this.open = false; },
                                clear() { this.id = ''; this.text = ''; this.active = 0; this.open = true; this.$refs.input.focus(); },
                                move(step) {
                                    this.open = true;
                                    const count = this.matches.length;
                                    if (count) { this.active = (this.active + step + count) % count; this.$nextTick(() => this.$refs.list.children[this.active]?.scrollIntoView({ block: 'nearest' })); }
                                },
                                pick() { const item = this.matches[this.active]; if (this.open && item) { this.choose(item[0]); } },
                            }" x-init="text = label()" x-effect="if (! open) text = label()"
                             x-on:click.outside="open = false; text = label()">
                            <label class="sr-only" for="filter-{{ $group['model'] }}">{{ $group['label'] }}</label>
                            <input id="filter-{{ $group['model'] }}" x-ref="input" type="text" role="combobox" autocomplete="off" aria-autocomplete="list"
                                   aria-controls="list-{{ $group['model'] }}" x-bind:aria-expanded="open" placeholder="All"
                                   x-model="text"
                                   x-on:focus="open = true; $el.select()"
                                   x-on:input="open = true; active = 0; if (text.trim() === '') { id = ''; }"
                                   x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
                                   x-on:keydown.enter.prevent="pick()" x-on:keydown.escape="open = false; text = label()">
                            <button type="button" class="combobox__clear" x-show="id !== ''" x-cloak x-on:click="clear()" aria-label="Clear {{ $group['label'] }} filter">&times;</button>
                            <ul id="list-{{ $group['model'] }}" class="combobox__list" role="listbox" x-ref="list" x-show="open && matches.length" x-cloak>
                                <template x-for="(item, index) in matches" x-bind:key="item[0]">
                                    <li role="option" x-bind:aria-selected="item[0] == id" x-bind:class="{ 'is-active': index === active, 'is-selected': item[0] == id }"
                                        x-text="item[1]" x-on:mousedown.prevent="choose(item[0])" x-on:mousemove="active = index"></li>
                                </template>
                            </ul>
                        </div>
                    </div>
                </details>
                @endif
            @endforeach
            @if ($hasFilters)
                <button type="button" class="text-link clear-filters" wire:click="clearFilters">Clear all filters</button>
            @endif
        </section>

        <nav class="filter-section" aria-label="A to Z index">
            <h3>A–Z index</h3>
            <div class="alphabet">
                @foreach (range('A', 'Z') as $letter)
                    <a href="{{ route('public.explore', ['letter' => $letter]) }}"
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

        @if ($specimens->hasPages() || $specimens->total() > min($perPageOptions))
        <div class="results-toolbar">
            <label for="per-page">Results per page:</label>
            <select id="per-page" wire:model.live="perPage">
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <div class="catalogue-pagination">{{ $specimens->links() }}</div>
    
        @endif
    </section>
</div>
