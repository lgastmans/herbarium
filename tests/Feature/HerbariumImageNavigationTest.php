<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerbariumImageNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_sees_desktop_and_responsive_import_links(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk();
        $content = $response->getContent();

        $this->assertSame(2, substr_count($content, 'Import Images'));
        $this->assertSame(2, substr_count($content, 'href="'.route('herbarium.images.import').'"'));
    }

    public function test_verified_non_administrator_sees_no_import_navigation_link(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Import Images')
            ->assertDontSee(route('herbarium.images.import'), false);
    }

    public function test_navigation_source_uses_the_named_route_and_gate_for_both_links(): void
    {
        $view = file_get_contents(resource_path('views/livewire/layout/navigation.blade.php'));

        $this->assertIsString($view);
        $this->assertSame(2, substr_count($view, "@can('import-herbarium-images')"));
        $this->assertSame(2, substr_count($view, "route('herbarium.images.import')"));
        $this->assertSame(2, substr_count($view, "{{ __('Import Images') }}"));
    }
}
