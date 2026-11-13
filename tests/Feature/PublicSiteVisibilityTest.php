<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_site_is_hidden_from_everyone_but_the_preview_accounts_until_enabled(): void
    {
        config([
            'herbarium.public_site_enabled' => false,
            'herbarium.public_site_preview_emails' => ['me@example.com'],
        ]);

        $this->get('/')->assertRedirect('/admin');
        $this->get(route('public.explore'))->assertRedirect('/admin');

        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/admin');
        $this->actingAs(User::factory()->admin()->create(['email' => 'client@example.com']))
            ->get('/')->assertRedirect('/admin');
    }

    public function test_preview_account_can_see_the_public_site_while_disabled(): void
    {
        config([
            'herbarium.public_site_enabled' => false,
            'herbarium.public_site_preview_emails' => ['me@example.com'],
        ]);

        $this->actingAs(User::factory()->admin()->create(['email' => 'Me@Example.com']))
            ->get(route('public.explore'))->assertOk();
    }

    public function test_everyone_can_see_the_public_site_when_enabled(): void
    {
        config(['herbarium.public_site_enabled' => true]);

        $this->get(route('public.explore'))->assertOk();
    }
}
