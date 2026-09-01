<?php

namespace App\Services\HerbariumImageMatching;

use App\Models\Herbarium;

final class HerbariumImageMatcher
{
    private const FILENAME_PATTERN = '/^(?<prefix>f\s*)?(?<number>\d+)(?:_(?<suffix>\d+))?\.(?<extension>jpe?g|png)$/iD';

    private const COLLECTION_NUMBER_PATTERN = '/^(?<prefix>f\s*)?(?<number>\d+)$/iD';

    /** @param  iterable<Herbarium>  $herbaria */
    public function buildLookup(iterable $herbaria): HerbariumImageLookup
    {
        $lookup = new HerbariumImageLookup();

        foreach ($herbaria as $herbarium) {
            $normalized = $this->normalizeCollectionNumber((string) $herbarium->collection_number);

            if ($normalized !== null) {
                $lookup->add($normalized, $herbarium);
            }
        }

        return $lookup;
    }

    public function match(string $filename, HerbariumImageLookup $lookup): HerbariumImageMatchResult
    {
        $parsed = $this->parseFilename($filename);

        if ($parsed === null) {
            return HerbariumImageMatchResult::invalid();
        }

        $exactCandidates = $lookup->candidatesFor($parsed['normalized']);

        if (count($exactCandidates) > 1) {
            return HerbariumImageMatchResult::ambiguous(
                $parsed['normalized'],
                HerbariumImageMatchType::Exact,
                $exactCandidates,
            );
        }

        if (count($exactCandidates) === 1) {
            return HerbariumImageMatchResult::matched(
                $parsed['normalized'],
                HerbariumImageMatchType::Exact,
                $exactCandidates[0],
            );
        }

        if ($parsed['has_f_prefix']) {
            return HerbariumImageMatchResult::unmatched($parsed['normalized']);
        }

        $fallback = 'F'.$parsed['normalized'];
        $fallbackCandidates = $lookup->candidatesFor($fallback);

        if (count($fallbackCandidates) > 1) {
            return HerbariumImageMatchResult::ambiguous(
                $fallback,
                HerbariumImageMatchType::FFallback,
                $fallbackCandidates,
            );
        }

        if (count($fallbackCandidates) === 1) {
            return HerbariumImageMatchResult::matched(
                $fallback,
                HerbariumImageMatchType::FFallback,
                $fallbackCandidates[0],
            );
        }

        return HerbariumImageMatchResult::unmatched($parsed['normalized']);
    }

    /** @return array{normalized: string, has_f_prefix: bool}|null */
    private function parseFilename(string $filename): ?array
    {
        if (! preg_match(self::FILENAME_PATTERN, $filename, $matches)) {
            return null;
        }

        if (isset($matches['suffix']) && $matches['suffix'] !== '' && ltrim($matches['suffix'], '0') === '') {
            return null;
        }

        $hasFPrefix = isset($matches['prefix']) && $matches['prefix'] !== '';
        $normalizedNumber = $this->normalizeNumericPortion($matches['number']);

        return [
            'normalized' => ($hasFPrefix ? 'F' : '').$normalizedNumber,
            'has_f_prefix' => $hasFPrefix,
        ];
    }

    private function normalizeCollectionNumber(string $collectionNumber): ?string
    {
        $collectionNumber = trim($collectionNumber);

        if (! preg_match(self::COLLECTION_NUMBER_PATTERN, $collectionNumber, $matches)) {
            return null;
        }

        $hasFPrefix = isset($matches['prefix']) && $matches['prefix'] !== '';

        return ($hasFPrefix ? 'F' : '').$this->normalizeNumericPortion($matches['number']);
    }

    private function normalizeNumericPortion(string $number): string
    {
        $normalized = ltrim($number, '0');

        return $normalized === '' ? '0' : $normalized;
    }
}
