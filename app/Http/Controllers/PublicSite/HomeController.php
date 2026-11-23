<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\Collector;
use App\Models\Herbarium;
use App\Models\Place;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $featuredCollections = Herbarium::query()
            ->onePerCollection()
            ->whereHas('images')
            ->with(['genus', 'family', 'place', 'images' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->limit(4)
            ->get();

        $collectorIds = $featuredCollections->flatMap(fn ($collection) => [
            $collection->collector1_id, $collection->collector2_id,
            $collection->collector3_id, $collection->collector4_id,
        ])->filter()->unique();
        $collectors = Collector::query()->whereKey($collectorIds)->get()->keyBy('id');

        $localities = Place::query()
            ->join('herbarium', 'herbarium.place_id', '=', 'places.id')
            ->whereIn('herbarium.id', Herbarium::firstRecordIds())
            ->selectRaw('places.id, places.name, COUNT(*) as collections_count')
            ->groupBy('places.id', 'places.name')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('places.id')
            ->limit(3)
            ->get();

        $records = Herbarium::query()->onePerCollection();
        $stats = [
            'collections' => (clone $records)->count(),
            'families' => (clone $records)->whereNotNull('family_id')->distinct()->count('family_id'),
            'localities' => (clone $records)->whereNotNull('place_id')->distinct()->count('place_id'),
        ];

        return view('public.home', compact('featuredCollections', 'localities', 'collectors', 'stats'));
    }
}
