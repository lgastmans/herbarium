<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicSite\HomeController;
use App\Livewire\PublicSite\Catalogue;
use Tests\TestCase;

class PublicLayoutTest extends TestCase
{
    public function test_home_introduces_the_herbarium_and_links_to_the_catalogue(): void
    {
        // Exercise the page layout independently of the catalogue database.
        $this->partialMock(HomeController::class, function ($mock) {
            $mock->shouldReceive('__invoke')->once()->andReturn(view('public.home', [
                'featuredCollections' => collect(),
                'localities' => collect(),
            ]));
        });

        $this->get('/')
            ->assertOk()
            ->assertSee('Explore the Auroville')
            ->assertSee('From the collection')
            ->assertSee('href="'.route('public.explore').'"', false)
            ->assertDontSeeLivewire(Catalogue::class)
            ->assertSee('href="#main-content"', false);
    }

    public function test_editorial_pages_are_public_and_identify_the_active_navigation_item(): void
    {
        foreach (['about' => 'Plants, places', 'contact' => 'Connect with'] as $page => $heading) {
            $this->get('/'.$page)
                ->assertOk()
                ->assertSee($heading)
                ->assertSee('href="'.route('public.'.$page).'"  aria-current="page"', false)
                ->assertDontSeeLivewire(Catalogue::class)
                ->assertDontSee('wire:submit', false);
        }
    }
}
