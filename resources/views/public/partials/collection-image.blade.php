@php
    $image = $collection?->images->first();
    $available = $image && \Illuminate\Support\Facades\Storage::disk('public')->exists('herbarium/'.$image->filename);
@endphp
<div class="{{ $imageClass ?? 'specimen-card__image' }}">
    @if ($available)
        <img class="collection-photo" src="{{ $image->url }}" alt="Herbarium sheet of {{ $collection->genus?->name }}, collection {{ $collection->collection_number }}" loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}" width="600" height="800">
    @else
        <div class="image-unavailable">@include('public.partials.icon', ['icon' => 'image'])<span>Specimen image unavailable</span>@if($collection)<span>Collection {{ $collection->collection_number }}</span>@endif</div>
    @endif
</div>
