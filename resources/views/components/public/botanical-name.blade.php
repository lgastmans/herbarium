@props(['name' => null])
@php
    // Botanical names are stored as one string: "Genus species Authority".
    // Genus, species and any infraspecific ranks are italic; the authorship is not.
    $words = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
    $scientific = array_splice($words, 0, 1);
    while ($words && preg_match('/^(×|\p{Ll})/u', $words[0])) {
        $scientific[] = array_shift($words);
    }
    $scientific = implode(' ', $scientific);
    $authorship = implode(' ', $words);
@endphp
@if ($scientific === '')
    <span>Botanical name not recorded</span>
@else
    <i class="scientific-name">{{ $scientific }}</i>@if ($authorship !== '') <span class="authorship">{{ $authorship }}</span>@endif
@endif
