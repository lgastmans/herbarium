<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\Specific;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerbariumCollectionSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_json_request_is_unauthorized(): void
    {
        $this->getJson(route('ajax.herbaria', ['search' => '123']))
            ->assertUnauthorized();
    }

    public function test_unverified_administrator_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->unverified()->create())
            ->getJson(route('ajax.herbaria', ['search' => '123']))
            ->assertForbidden();
    }

    public function test_verified_non_administrator_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('ajax.herbaria', ['search' => '123']))
            ->assertForbidden();
    }

    public function test_verified_administrator_receives_exact_selector_shape(): void
    {
        $herbarium = $this->herbarium('123', 'Acacia', 'nilotica');
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->getJson(route('ajax.herbaria', ['search' => '123']))
            ->assertOk()
            ->assertJsonCount(1);

        $result = $response->json()[0];

        $this->assertSame([
            'id',
            'collection_number',
            'genus',
            'specific_name',
            'botanical_name',
            'label',
        ], array_keys($result));
        $this->assertSame($herbarium->id, $result['id']);
        $this->assertSame('123', $result['collection_number']);
        $this->assertSame('Acacia', $result['genus']);
        $this->assertSame('nilotica', $result['specific_name']);
        $this->assertSame('Acacia nilotica', $result['botanical_name']);
        $this->assertSame('123 — Acacia nilotica', $result['label']);
        $this->assertArrayNotHasKey('notes', $result);
        $this->assertArrayNotHasKey('herbarium_number', $result);
    }

    public function test_numeric_and_f_searches_handle_spacing_and_leading_zeroes(): void
    {
        $numeric = $this->herbarium('00123', 'Azadirachta', 'indica');
        $prefixed = $this->herbarium('F 00123', 'Ficus', 'benghalensis');
        $this->actingAs(User::factory()->admin()->create());

        foreach (['123', '00123'] as $search) {
            $this->getJson(route('ajax.herbaria', ['search' => $search]))
                ->assertOk()
                ->assertJsonFragment(['id' => $numeric->id])
                ->assertJsonMissing(['id' => $prefixed->id]);
        }

        foreach (['F123', 'F 123', 'f 00123'] as $search) {
            $this->getJson(route('ajax.herbaria', ['search' => $search]))
                ->assertOk()
                ->assertJsonFragment(['id' => $prefixed->id])
                ->assertJsonMissing(['id' => $numeric->id]);
        }
    }

    public function test_null_specific_name_has_sensible_botanical_formatting(): void
    {
        $this->herbarium('124', 'Ficus');
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson(route('ajax.herbaria', ['search' => '124']))
            ->assertOk()
            ->assertExactJson([[
                'id' => Herbarium::query()->value('id'),
                'collection_number' => '124',
                'genus' => 'Ficus',
                'specific_name' => null,
                'botanical_name' => 'Ficus',
                'label' => '124 — Ficus',
            ]]);
    }

    public function test_selected_scalar_and_array_resolution_follow_wireui_convention(): void
    {
        $first = $this->herbarium('200', 'Acacia', 'auriculiformis');
        $second = $this->herbarium('100', 'Ficus');
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson(route('ajax.herbaria', ['selected' => $first->id]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $first->id]);

        $response = $this->getJson(route('ajax.herbaria', [
            'selected' => [$first->id, $second->id],
        ]))->assertOk()->assertJsonCount(2);

        $this->assertSame([$second->id, $first->id], array_column($response->json(), 'id'));
    }

    public function test_empty_search_returns_no_broad_listing(): void
    {
        $this->herbarium('300', 'Ficus');
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson(route('ajax.herbaria'))
            ->assertOk()
            ->assertExactJson([]);
        $this->getJson(route('ajax.herbaria', ['search' => '   ']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_results_are_capped_at_25_and_deterministically_ordered(): void
    {
        for ($index = 29; $index >= 0; $index--) {
            $this->herbarium((string) (1000 + $index), 'Genus'.$index);
        }

        $this->actingAs(User::factory()->admin()->create());
        $response = $this->getJson(route('ajax.herbaria', ['search' => '1']))
            ->assertOk()
            ->assertJsonCount(25);

        $collectionNumbers = array_column($response->json(), 'collection_number');
        $sorted = $collectionNumbers;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $collectionNumbers);
        $this->assertSame('1000', $collectionNumbers[0]);
        $this->assertSame('1024', $collectionNumbers[24]);
    }

    public function test_selected_resolution_can_exceed_search_limit_but_has_a_safe_cap(): void
    {
        $ids = [];

        for ($index = 0; $index < 30; $index++) {
            $ids[] = $this->herbarium((string) (4000 + $index), 'Genus'.$index)->id;
        }

        $this->actingAs(User::factory()->admin()->create());
        $this->getJson(route('ajax.herbaria', ['selected' => $ids]))
            ->assertOk()
            ->assertJsonCount(30);

        $this->getJson(route('ajax.herbaria', ['selected' => range(1, 51)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selected');
    }

    public function test_oversized_malformed_and_wildcard_inputs_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson(route('ajax.herbaria', ['search' => str_repeat('1', 33)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
        $this->getJson(route('ajax.herbaria', ['search' => ['123']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
        $this->getJson(route('ajax.herbaria', ['search' => '%']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
        $this->getJson(route('ajax.herbaria', ['search' => '_']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    private function herbarium(
        string $collectionNumber,
        string $genusName,
        ?string $specificName = null,
    ): Herbarium {
        $family = Family::create(['family' => 'Family '.uniqid()]);
        $genus = Genus::create(['name' => $genusName]);
        $specific = $specificName === null ? null : Specific::create(['name' => $specificName]);

        return Herbarium::create([
            'family_id' => $family->id,
            'genus_id' => $genus->id,
            'specific_id' => $specific?->id,
            'collection_number' => $collectionNumber,
            'notes' => 'must not be exposed',
            'herbarium_number' => 'private-field',
        ]);
    }
}
