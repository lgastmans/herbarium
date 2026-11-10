<?php

namespace Tests\Feature;

use App\Livewire\PublicSite\Catalogue;
use App\Models\Collector;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PublicSpecimenTest extends TestCase
{
    use RefreshDatabase;

    public function test_explore_lists_one_card_per_collection_linking_to_its_record(): void
    {
        $this->collection('3466', 'Barleria prionitis L.');
        $this->collection('3466', 'Barleria prionitis L.');
        $this->collection('223', 'Ancistrocladus tectorius (Lour.) Merr.');

        $this->get(route('public.explore'))->assertOk()
            ->assertSee('Explore the collection')
            ->assertSee('href="'.route('public.specimen', '3466').'"', false)
            ->assertSee('<i class="scientific-name">Barleria prionitis</i>', false)
            ->assertSee('<span class="authorship">(Lour.) Merr.</span>', false);

        Livewire::test(Catalogue::class)->assertViewHas('specimens', fn ($page) => $page->total() === 2);
    }

    public function test_search_matches_collectors_and_places(): void
    {
        $collector = Collector::create(['name' => 'Jan', 'surname' => 'den Hollander']);
        $place = Place::create(['name' => 'Chengelpattu']);
        $this->collection('1', 'Alpha one L.', ['collector2_id' => $collector->id]);
        $this->collection('2', 'Beta two L.', ['place_id' => $place->id]);
        $this->collection('3', 'Gamma three L.');

        Livewire::test(Catalogue::class)->set('search', 'Hollander')
            ->assertSee('Alpha one')->assertDontSee('Beta two')->assertDontSee('Gamma three')
            ->set('search', 'chengel')
            ->assertSee('Beta two')->assertDontSee('Alpha one')
            ->set('search', '100%')->assertSee('No collections found');
    }

    public function test_filters_letter_index_and_browse_order(): void
    {
        $acanthaceae = Family::create(['family' => 'Acanthaceae']);
        $boraginaceae = Family::create(['family' => 'Boraginaceae']);
        $this->collection('1', 'Zeta last L.', ['family_id' => $acanthaceae->id, 'collected_on' => '2002-03-15']);
        $this->collection('2', 'Alpha first L.', ['family_id' => $boraginaceae->id, 'collected_on' => '1996-03-27']);

        Livewire::test(Catalogue::class)
            ->assertSeeInOrder(['Alpha first', 'Zeta last'])
            ->call('browseBy', 'family')->assertSeeInOrder(['Zeta last', 'Alpha first'])
            ->set('family', (string) $boraginaceae->id)->assertSee('Alpha first')->assertDontSee('Zeta last')
            ->call('clearFilters')->set('year', '2002')->assertSee('Zeta last')->assertDontSee('Alpha first')
            ->call('clearFilters')->call('browseBy', 'species')->call('chooseLetter', 'A')->assertSee('Alpha first')->assertDontSee('Zeta last')
            ->call('chooseLetter', 'A')->assertSee('Zeta last');
    }

    public function test_unexpected_url_values_fall_back_to_safe_defaults(): void
    {
        $this->collection('1', 'Alpha first L.');

        Livewire::withQueryParams(['perPage' => '9999', 'browse' => 'nonsense', 'letter' => "'; --", 'year' => 'abc', 'family' => 'x'])
            ->test(Catalogue::class)
            ->assertOk()->assertSee('Alpha first')
            ->assertViewHas('specimens', fn ($page) => $page->perPage() === 24);
    }

    public function test_specimen_page_shows_the_record_and_neighbours(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('herbarium/3466.jpg', 'image');
        $collector = Collector::create(['name' => 'W.F.', 'surname' => 'Gastmans']);
        $place = Place::create(['name' => 'Chengelpattu, Thirukalikundram']);
        $this->collection('223', 'Ancistrocladus tectorius (Lour.) Merr.');
        $current = $this->collection('3466', 'Barleria prionitis L.', [
            'place_id' => $place->id, 'collector1_id' => $collector->id, 'collected_on' => '2002-03-15',
            'vernacular_name' => 'Kaattukanagaambaram', 'herbarium_number' => '7086', 'habit' => 'Sub-shrub',
            'notes' => 'On the temple hill.', 'latitude' => '12° 36.52\' N',
        ]);
        HerbariumImages::create(['herbarium_id' => $current->id, 'genus_id' => $current->genus_id, 'filename' => '3466.jpg']);
        $this->collection('3500', 'Ehretia microphylla Lam.');

        $this->get(route('public.specimen', '3466'))->assertOk()
            ->assertSee('Collection 3466')
            ->assertSee('Barleria prionitis')
            ->assertSee('Kaattukanagaambaram')
            ->assertSeeInOrder(['Family', 'Location', 'Chengelpattu, Thirukalikundram', 'Collected', '15 Mar 2002', 'W.F. Gastmans'])
            ->assertSeeInOrder(['Collection details', 'Accession number', '7086', 'Habit', 'Sub-shrub'])
            ->assertSee('Field observations')->assertSee('On the temple hill.')
            ->assertDontSee('Taluk')
            ->assertSee('data-viewer-image', false)
            ->assertSee('href="'.route('public.specimen', '223').'" rel="prev"', false)
            ->assertSee('href="'.route('public.specimen', '3500').'" rel="next"', false)
            ->assertSee('Continue browsing');
    }

    public function test_specimen_page_without_image_or_unknown_collection(): void
    {
        $this->collection('55', 'Alpha first L.');

        $this->get(route('public.specimen', '55'))->assertOk()->assertSee('Specimen image unavailable')
            ->assertDontSee('data-viewer-download', false);
        $this->get(route('public.specimen', 'missing'))->assertNotFound();
    }

    public function test_specimen_back_link_keeps_the_explore_filters(): void
    {
        $this->collection('55', 'Alpha first L.');

        $this->get(route('public.specimen', '55'), ['referer' => route('public.explore', ['search' => 'alpha'])])
            ->assertSee('href="'.route('public.explore', ['search' => 'alpha']).'"', false);
        $this->get(route('public.specimen', '55'), ['referer' => 'https://elsewhere.test/explore?search=x'])
            ->assertDontSee('elsewhere.test');
    }

    private function collection(string $number, string $name, array $attributes = []): Herbarium
    {
        return Herbarium::create($attributes + [
            'family_id' => Family::firstOrCreate(['family' => 'Acanthaceae'])->id,
            'genus_id' => Genus::firstOrCreate(['name' => $name])->id,
            'collection_number' => $number,
        ]);
    }
}
