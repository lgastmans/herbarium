<?php

namespace Tests\Feature;

use App\Livewire\ImportHerbariumImages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImportHerbariumImagesAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('herbarium.images.import'))
            ->assertRedirect(route('login'));
    }

    public function test_unverified_administrator_is_redirected_to_email_verification(): void
    {
        $user = User::factory()->admin()->unverified()->create();

        $this->actingAs($user)
            ->get(route('herbarium.images.import'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_non_administrator_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('herbarium.images.import'))
            ->assertForbidden();
    }

    public function test_verified_administrator_can_view_page_shell(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get(route('herbarium.images.import'))
            ->assertOk()
            ->assertSee('Import Herbarium Images')
            ->assertSee('Add up to 100 JPEG or PNG images');
    }

    public function test_direct_livewire_mount_is_rejected_for_non_administrator(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ImportHerbariumImages::class)
            ->assertForbidden();
    }

    public function test_direct_livewire_mount_succeeds_for_verified_administrator(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ImportHerbariumImages::class)
            ->assertSee('Import Herbarium Images');
    }
}
