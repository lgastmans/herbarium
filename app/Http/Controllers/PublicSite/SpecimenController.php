<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\Collector;
use App\Models\Herbarium;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SpecimenController extends Controller
{
    public function __invoke(Request $request, string $collection): View
    {
        $records = Herbarium::query()
            ->where('collection_number', $collection)
            ->with([
                'genus', 'family', 'place', 'taluk', 'district', 'state',
                'images' => fn ($query) => $query->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        abort_if($records->isEmpty(), 404);

        // A collection can span several records; the first one describes it.
        $specimen = $records->first();

        $images = $records->flatMap->images
            ->filter(fn ($image) => Storage::disk('public')->exists('herbarium/'.$image->filename))
            ->values();

        $collectorIds = collect([$specimen->collector1_id, $specimen->collector2_id, $specimen->collector3_id, $specimen->collector4_id])->filter();
        $collectorsById = Collector::query()->whereKey($collectorIds)->get()->keyBy('id');
        $collectors = $collectorIds->map(fn ($id) => trim((string) $collectorsById->get($id)?->display_collector))
            ->filter()->unique()->implode('; ');

        $neighbour = fn () => Herbarium::query()->onePerCollection()
            ->with(['genus', 'images' => fn ($query) => $query->orderBy('id')]);
        $before = $neighbour()->where('herbarium.id', '<', $specimen->id)->orderByDesc('herbarium.id')->limit(2)->get()->reverse()->values();
        $after = $neighbour()->where('herbarium.id', '>', $specimen->id)->orderBy('herbarium.id')->limit(3)->get();

        // A window of four records around this one; it slides when near either end.
        $strip = $before->concat([$specimen])->concat($after);
        $strip = $strip->slice(min(max($before->count() - 2, 0), max($strip->count() - 4, 0)), 4)->values();

        return view('public.specimen', [
            'specimen' => $specimen,
            'images' => $images,
            'collectors' => $collectors,
            'previous' => $before->last(),
            'next' => $after->first(),
            'strip' => $strip,
            'backUrl' => $this->resultsUrl($request),
        ]);
    }

    /** Return to the filtered Explore page the visitor came from, if any. */
    private function resultsUrl(Request $request): string
    {
        $referrer = parse_url($request->headers->get('referer', ''));

        if (($referrer['host'] ?? null) === $request->getHost()
            && ($referrer['path'] ?? null) === route('public.explore', [], false)) {
            return route('public.explore').(isset($referrer['query']) ? '?'.$referrer['query'] : '');
        }

        return route('public.explore');
    }
}
