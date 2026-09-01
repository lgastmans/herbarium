<?php

namespace App\Services\HerbariumImageMatching;

use App\Models\Herbarium;

final class HerbariumImageLookup
{
    /** @var array<string, list<Herbarium>> */
    private array $candidates = [];

    public function add(string $normalizedCollectionNumber, Herbarium $herbarium): void
    {
        $this->candidates[$normalizedCollectionNumber][] = $herbarium;
    }

    /** @return list<Herbarium> */
    public function candidatesFor(string $normalizedCollectionNumber): array
    {
        return $this->candidates[$normalizedCollectionNumber] ?? [];
    }
}
