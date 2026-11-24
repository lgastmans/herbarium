<?php

namespace App\Livewire\PublicSite;

use App\Models\Collector;
use App\Models\District;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Catalogue extends Component
{
    use WithPagination;

    public const PER_PAGE_OPTIONS = [24, 48, 96];

    #[Url(except: '')]
    public string $search = '';

    /** Id of the chosen species (a genus table row, which holds the full botanical name). */
    #[Url(except: '')]
    public string $species = '';

    #[Url(except: '')]
    public string $family = '';

    #[Url(except: '')]
    public string $district = '';

    #[Url(except: '')]
    public string $place = '';

    #[Url(except: '')]
    public string $collector = '';

    #[Url(except: '')]
    public string $year = '';

    #[Url(except: '')]
    public string $letter = '';

    #[Url(except: '24')]
    public string $perPage = '24';

    public function paginationView(): string
    {
        return 'public.partials.pagination';
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /** Autocomplete for the species filter: names that start with or contain the typed text. */
    #[Renderless]
    public function suggestSpecies(string $term): array
    {
        $term = mb_substr(trim($term), 0, 100);
        if ($term === '') {
            return [];
        }

        $escaped = addcslashes($term, '%_\\');

        return Genus::query()
            ->whereIn('id', Herbarium::query()->whereNotNull('genus_id')->select('genus_id'))
            ->where('name', 'like', '%'.$escaped.'%')
            ->orderByRaw('name like ? desc', [$escaped.'%'])
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name'])
            ->map(fn (Genus $genus) => ['id' => (string) $genus->id, 'name' => $genus->name])
            ->all();
    }

    public function chooseLetter(string $letter): void
    {
        $this->letter = $this->letter === $letter ? '' : $letter;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'species', 'family', 'district', 'place', 'collector', 'year', 'letter');
        $this->resetPage();
    }

    public function render(): View
    {
        $perPage = in_array((int) $this->perPage, self::PER_PAGE_OPTIONS, true) ? (int) $this->perPage : 24;

        $specimens = Herbarium::query()
            ->onePerCollection()
            ->select('herbarium.*')
            ->leftJoin('genus', 'genus.id', '=', 'herbarium.genus_id')
            ->leftJoin('families', 'families.id', '=', 'herbarium.family_id')
            ->with(['genus', 'family', 'images' => fn ($query) => $query->orderBy('id')])
            ->tap(fn (Builder $query) => $this->applySearch($query))
            ->when(ctype_digit($this->species), fn ($query) => $query->where('herbarium.genus_id', $this->species))
            ->when(ctype_digit($this->family), fn ($query) => $query->where('herbarium.family_id', $this->family))
            ->when(ctype_digit($this->district), fn ($query) => $query->where('herbarium.district_id', $this->district))
            ->when(ctype_digit($this->place), fn ($query) => $query->where('herbarium.place_id', $this->place))
            ->when(ctype_digit($this->collector), fn ($query) => $query->where(function ($query) {
                foreach (['collector1_id', 'collector2_id', 'collector3_id', 'collector4_id'] as $column) {
                    $query->orWhere('herbarium.'.$column, $this->collector);
                }
            }))
            ->when(preg_match('/^\d{4}$/', $this->year), fn ($query) => $query->whereBetween(
                'herbarium.collected_on', [$this->year.'-01-01', $this->year.'-12-31']
            ))
            ->when(preg_match('/^[A-Z]$/', $this->letter), fn ($query) => $query->where('genus.name', 'like', $this->letter.'%'))
            ->orderBy('genus.name')
            ->orderBy('herbarium.id')
            ->paginate($perPage);

        return view('public.livewire.catalogue', [
            'specimens' => $specimens,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'filters' => $this->filterOptions(),
            'speciesLabel' => ctype_digit($this->species) ? (string) Genus::query()->whereKey($this->species)->value('name') : '',
            'hasFilters' => collect([$this->search, $this->species, $this->family, $this->district, $this->place, $this->collector, $this->year, $this->letter])
                ->contains(fn ($value) => $value !== ''),
        ]);
    }

    private function applySearch(Builder $query): void
    {
        $search = mb_substr(trim($this->search), 0, 200);
        if ($search === '') {
            return;
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $collectorIds = Collector::query()
            ->where('name', 'like', $like)->orWhere('surname', 'like', $like)
            ->pluck('id');

        $query->where(function (Builder $query) use ($like, $collectorIds) {
            $query->where('herbarium.collection_number', 'like', $like)
                ->orWhere('herbarium.vernacular_name', 'like', $like)
                ->orWhere('genus.name', 'like', $like)
                ->orWhere('families.family', 'like', $like)
                ->orWhereHas('place', fn ($query) => $query->where('name', 'like', $like));

            if ($collectorIds->isNotEmpty()) {
                foreach (['collector1_id', 'collector2_id', 'collector3_id', 'collector4_id'] as $column) {
                    $query->orWhereIn('herbarium.'.$column, $collectorIds);
                }
            }
        });
    }

    /** Choices for the filter drop-downs: only values used by at least one record. */
    private function filterOptions(): array
    {
        $used = fn (string $column) => Herbarium::query()->whereNotNull($column)->select($column)->distinct();

        $collectorColumns = ['collector1_id', 'collector2_id', 'collector3_id', 'collector4_id'];
        $collectorIds = collect($collectorColumns)
            ->flatMap(fn ($column) => $used($column)->pluck($column))->unique();

        return [
            'families' => Family::query()->whereIn('id', $used('family_id'))->orderBy('family')->pluck('family', 'id'),
            'districts' => District::query()->whereIn('id', $used('district_id'))->orderBy('name')->pluck('name', 'id'),
            'places' => Place::query()->whereIn('id', $used('place_id'))->orderBy('name')->pluck('name', 'id'),
            'collectors' => Collector::query()->whereKey($collectorIds)->get()
                ->mapWithKeys(fn (Collector $collector) => [$collector->id => trim($collector->display_collector)])
                ->sortBy(fn (string $name) => mb_strtolower($name)),
            'years' => $used('collected_on')->pluck('collected_on')
                ->map(fn ($date) => substr((string) $date, 0, 4))->unique()->sortDesc()
                ->mapWithKeys(fn ($year) => [$year => $year]),
        ];
    }
}
