<?php

namespace Tests\Unit;

use App\Models\Herbarium;
use App\Services\HerbariumImageMatching\HerbariumImageMatcher;
use App\Services\HerbariumImageMatching\HerbariumImageMatchStatus;
use App\Services\HerbariumImageMatching\HerbariumImageMatchType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HerbariumImageMatcherTest extends TestCase
{
    private HerbariumImageMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new HerbariumImageMatcher();
    }

    #[DataProvider('validFilenameProvider')]
    public function test_it_parses_and_normalizes_valid_filenames(string $filename, string $normalized): void
    {
        $result = $this->matcher->match($filename, $this->matcher->buildLookup([]));

        $this->assertSame(HerbariumImageMatchStatus::Unmatched, $result->status);
        $this->assertSame($normalized, $result->normalizedCollectionNumber);
        $this->assertNull($result->matchType);
        $this->assertSame([], $result->candidates);
    }

    public static function validFilenameProvider(): array
    {
        return [
            'numeric jpg' => ['123.jpg', '123'],
            'numeric leading zeroes and uppercase png' => ['00123.PNG', '123'],
            'numeric suffix and jpeg' => ['123_2.jpeg', '123'],
            'positive suffix with leading zero' => ['123_02.jpeg', '123'],
            'numeric zero' => ['000.jpg', '0'],
            'spaced F' => ['F 123.jpg', 'F123'],
            'unspaced F' => ['F123.jpg', 'F123'],
            'lowercase F, leading zeroes, suffix, uppercase extension' => ['f 00123_2.JPG', 'F123'],
            'F zero' => ['F000.jpeg', 'F0'],
        ];
    }

    #[DataProvider('invalidFilenameProvider')]
    public function test_it_rejects_unsupported_or_malformed_filenames(string $filename): void
    {
        $result = $this->matcher->match($filename, $this->matcher->buildLookup([]));

        $this->assertSame(HerbariumImageMatchStatus::Invalid, $result->status);
        $this->assertNull($result->normalizedCollectionNumber);
        $this->assertNull($result->matchType);
        $this->assertSame([], $result->candidates);
    }

    public static function invalidFilenameProvider(): array
    {
        return [
            'unsupported extension' => ['123.gif'],
            'missing number' => ['F.jpg'],
            'alphabetic name' => ['specimen.jpg'],
            'zero suffix' => ['123_0.jpg'],
            'all-zero suffix' => ['123_000.jpg'],
            'negative suffix' => ['123_-2.jpg'],
            'extra suffix' => ['123_2_extra.jpg'],
            'underscore after F' => ['F_123.jpg'],
            'path rather than filename' => ['folder/123.jpg'],
        ];
    }

    public function test_it_returns_an_exact_numeric_match(): void
    {
        $herbarium = $this->herbarium(10, '00123');
        $result = $this->matcher->match('123.PNG', $this->matcher->buildLookup([$herbarium]));

        $this->assertSame(HerbariumImageMatchStatus::Matched, $result->status);
        $this->assertSame(HerbariumImageMatchType::Exact, $result->matchType);
        $this->assertSame('123', $result->normalizedCollectionNumber);
        $this->assertSame($herbarium, $result->matchedHerbarium());
        $this->assertSame([10], $result->candidateIds());
    }

    public function test_it_normalizes_f_collection_numbers_for_exact_matches(): void
    {
        $herbarium = $this->herbarium(20, 'F 00123');
        $lookup = $this->matcher->buildLookup([$herbarium]);

        foreach (['F 123.jpg', 'F123.jpg', 'f 00123_2.JPG'] as $filename) {
            $result = $this->matcher->match($filename, $lookup);

            $this->assertSame(HerbariumImageMatchStatus::Matched, $result->status);
            $this->assertSame(HerbariumImageMatchType::Exact, $result->matchType);
            $this->assertSame('F123', $result->normalizedCollectionNumber);
            $this->assertSame($herbarium, $result->matchedHerbarium());
        }
    }

    public function test_it_uses_f_fallback_for_an_unprefixed_numeric_filename(): void
    {
        $herbarium = $this->herbarium(30, 'F 00123');
        $result = $this->matcher->match('00123_4.jpeg', $this->matcher->buildLookup([$herbarium]));

        $this->assertSame(HerbariumImageMatchStatus::Matched, $result->status);
        $this->assertSame(HerbariumImageMatchType::FFallback, $result->matchType);
        $this->assertSame('F123', $result->normalizedCollectionNumber);
        $this->assertSame($herbarium, $result->matchedHerbarium());
    }

    public function test_it_returns_unmatched_when_no_candidate_exists(): void
    {
        $result = $this->matcher->match(
            '999.jpg',
            $this->matcher->buildLookup([$this->herbarium(40, '123')]),
        );

        $this->assertSame(HerbariumImageMatchStatus::Unmatched, $result->status);
        $this->assertSame('999', $result->normalizedCollectionNumber);
        $this->assertNull($result->matchedHerbarium());
    }

    public function test_it_preserves_all_candidates_for_an_exact_normalized_collision(): void
    {
        $first = $this->herbarium(51, '123');
        $second = $this->herbarium(52, '00123');
        $result = $this->matcher->match('000123.jpg', $this->matcher->buildLookup([$first, $second]));

        $this->assertSame(HerbariumImageMatchStatus::Ambiguous, $result->status);
        $this->assertSame(HerbariumImageMatchType::Exact, $result->matchType);
        $this->assertSame('123', $result->normalizedCollectionNumber);
        $this->assertSame([$first, $second], $result->candidates);
        $this->assertSame([51, 52], $result->candidateIds());
        $this->assertNull($result->matchedHerbarium());
    }

    public function test_it_preserves_all_candidates_for_a_fallback_normalized_collision(): void
    {
        $first = $this->herbarium(61, 'F123');
        $second = $this->herbarium(62, 'f 00123');
        $result = $this->matcher->match('123.jpg', $this->matcher->buildLookup([$first, $second]));

        $this->assertSame(HerbariumImageMatchStatus::Ambiguous, $result->status);
        $this->assertSame(HerbariumImageMatchType::FFallback, $result->matchType);
        $this->assertSame('F123', $result->normalizedCollectionNumber);
        $this->assertSame([61, 62], $result->candidateIds());
    }

    public function test_exact_match_takes_precedence_over_f_fallback(): void
    {
        $exact = $this->herbarium(71, '00123');
        $fallback = $this->herbarium(72, 'F 00123');
        $result = $this->matcher->match('123.jpg', $this->matcher->buildLookup([$exact, $fallback]));

        $this->assertSame(HerbariumImageMatchStatus::Matched, $result->status);
        $this->assertSame(HerbariumImageMatchType::Exact, $result->matchType);
        $this->assertSame($exact, $result->matchedHerbarium());
    }

    public function test_exact_ambiguity_does_not_proceed_to_f_fallback(): void
    {
        $first = $this->herbarium(81, '123');
        $second = $this->herbarium(82, '00123');
        $fallback = $this->herbarium(83, 'F123');
        $result = $this->matcher->match('123.jpg', $this->matcher->buildLookup([$first, $second, $fallback]));

        $this->assertSame(HerbariumImageMatchStatus::Ambiguous, $result->status);
        $this->assertSame(HerbariumImageMatchType::Exact, $result->matchType);
        $this->assertSame([81, 82], $result->candidateIds());
    }

    public function test_explicit_f_filename_does_not_fall_back_to_numeric_collection(): void
    {
        $result = $this->matcher->match(
            'F123.jpg',
            $this->matcher->buildLookup([$this->herbarium(90, '123')]),
        );

        $this->assertSame(HerbariumImageMatchStatus::Unmatched, $result->status);
        $this->assertSame('F123', $result->normalizedCollectionNumber);
    }

    private function herbarium(int $id, string $collectionNumber): Herbarium
    {
        $herbarium = new Herbarium(['collection_number' => $collectionNumber]);
        $herbarium->setAttribute('id', $id);

        return $herbarium;
    }
}
