<?php

namespace App\Services\HerbariumImageMatching;

use App\Models\Herbarium;

final class HerbariumImageMatchResult
{
    /**
     * @param  list<Herbarium>  $candidates
     */
    private function __construct(
        public readonly HerbariumImageMatchStatus $status,
        public readonly ?HerbariumImageMatchType $matchType,
        public readonly ?string $normalizedCollectionNumber,
        public readonly array $candidates,
    ) {
    }

    public static function invalid(): self
    {
        return new self(HerbariumImageMatchStatus::Invalid, null, null, []);
    }

    public static function unmatched(string $normalizedCollectionNumber): self
    {
        return new self(
            HerbariumImageMatchStatus::Unmatched,
            null,
            $normalizedCollectionNumber,
            [],
        );
    }

    /** @param  list<Herbarium>  $candidates */
    public static function ambiguous(
        string $normalizedCollectionNumber,
        HerbariumImageMatchType $matchType,
        array $candidates,
    ): self {
        return new self(
            HerbariumImageMatchStatus::Ambiguous,
            $matchType,
            $normalizedCollectionNumber,
            array_values($candidates),
        );
    }

    public static function matched(
        string $normalizedCollectionNumber,
        HerbariumImageMatchType $matchType,
        Herbarium $herbarium,
    ): self {
        return new self(
            HerbariumImageMatchStatus::Matched,
            $matchType,
            $normalizedCollectionNumber,
            [$herbarium],
        );
    }

    public function matchedHerbarium(): ?Herbarium
    {
        if ($this->status !== HerbariumImageMatchStatus::Matched) {
            return null;
        }

        return $this->candidates[0];
    }

    /** @return list<int|string> */
    public function candidateIds(): array
    {
        return array_values(array_map(
            static fn (Herbarium $herbarium): int|string => $herbarium->getKey(),
            $this->candidates,
        ));
    }
}
