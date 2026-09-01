<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class HerbariumCollectionSearchController extends Controller
{
    private const RESULT_LIMIT = 25;

    private const SELECTED_LIMIT = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $selectedIds = $this->validatedSelectedIds($request);

        if ($selectedIds !== null) {
            if ($selectedIds === []) {
                return response()->json([]);
            }

            return response()->json(
                $this->formatResults(
                    $this->baseQuery()
                        ->whereIn('herbarium.id', $selectedIds)
                        ->orderBy('herbarium.collection_number')
                        ->orderBy('herbarium.id')
                        ->get()
                )
            );
        }

        $search = $request->input('search');

        if ($search === null || (is_string($search) && trim($search) === '')) {
            return response()->json([]);
        }

        $search = is_string($search) ? trim($search) : $search;

        Validator::make(
            ['search' => $search],
            ['search' => ['required', 'string', 'max:32', 'regex:/^(?:F\s*)?\d+$/i']],
        )->validate();

        preg_match('/^(?<prefix>F\s*)?(?<number>\d+)$/i', $search, $matches);

        $hasFPrefix = isset($matches['prefix']) && $matches['prefix'] !== '';
        $normalizedNumber = ltrim($matches['number'], '0');
        $normalizedNumber = $normalizedNumber === '' ? '0' : $normalizedNumber;
        $escapedNumber = $this->escapeLike($normalizedNumber);
        $compactCollection = "UPPER(REPLACE(herbarium.collection_number, ' ', ''))";
        $query = $this->baseQuery();

        if ($hasFPrefix) {
            $query
                ->whereRaw("{$compactCollection} REGEXP '^F[0-9]+$'")
                ->whereRaw(
                    "CAST(CAST(SUBSTRING({$compactCollection}, 2) AS UNSIGNED) AS CHAR) LIKE ?",
                    [$escapedNumber.'%'],
                );
        } else {
            $query
                ->whereRaw("{$compactCollection} REGEXP '^[0-9]+$'")
                ->whereRaw(
                    "CAST(CAST({$compactCollection} AS UNSIGNED) AS CHAR) LIKE ?",
                    [$escapedNumber.'%'],
                );
        }

        return response()->json(
            $this->formatResults(
                $query
                    ->orderBy('herbarium.collection_number')
                    ->orderBy('herbarium.id')
                    ->limit(self::RESULT_LIMIT)
                    ->get()
            )
        );
    }

    private function baseQuery(): Builder
    {
        return DB::table('herbarium')
            ->leftJoin('genus', 'herbarium.genus_id', '=', 'genus.id')
            ->leftJoin('specifics', 'herbarium.specific_id', '=', 'specifics.id')
            ->select([
                'herbarium.id',
                'herbarium.collection_number',
                'genus.name as genus',
                'specifics.name as specific_name',
            ]);
    }

    /** @return list<int>|null */
    private function validatedSelectedIds(Request $request): ?array
    {
        if (! $request->has('selected')) {
            return null;
        }

        $selected = $request->input('selected');
        $selected = is_array($selected) ? $selected : [$selected];

        Validator::make(
            ['selected' => $selected],
            [
                'selected' => ['array', 'max:'.self::SELECTED_LIMIT],
                'selected.*' => ['integer', 'min:1', 'distinct'],
            ],
        )->validate();

        return array_values(array_map('intval', $selected));
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array{id: int, collection_number: string, genus: string, specific_name: string|null, botanical_name: string, label: string}>
     */
    private function formatResults(iterable $rows): array
    {
        $results = [];

        foreach ($rows as $row) {
            $genus = trim((string) ($row->genus ?? ''));
            $specificName = $row->specific_name === null ? null : trim((string) $row->specific_name);
            $botanicalName = trim($genus.' '.($specificName ?? ''));
            $collectionNumber = (string) $row->collection_number;

            $results[] = [
                'id' => (int) $row->id,
                'collection_number' => $collectionNumber,
                'genus' => $genus,
                'specific_name' => $specificName,
                'botanical_name' => $botanicalName,
                'label' => $botanicalName === ''
                    ? $collectionNumber
                    : $collectionNumber.' — '.$botanicalName,
            ];
        }

        return $results;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
