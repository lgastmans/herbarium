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
            ->select('places.id', 'places.name')
            ->groupBy('places.id', 'places.name')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('places.id')
            ->limit(3)
            ->get();

        return view('public.home', compact('featuredCollections', 'localities', 'collectors'));
    }
}
