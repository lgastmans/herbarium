<?php

namespace Tests\Feature;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureUserIsAdministrator;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

class HerbariumImageAuthorizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_admin_is_cast_to_boolean_and_factory_admin_state_sets_it(): void
    {
        $administrator = User::factory()->admin()->create()->fresh();
        $regularUser = User::factory()->create()->fresh();

        $this->assertIsBool($administrator->is_admin);
        $this->assertTrue($administrator->is_admin);
        $this->assertIsBool($regularUser->is_admin);
        $this->assertFalse($regularUser->is_admin);
    }

    public function test_import_gate_allows_only_administrators(): void
    {
        $administrator = User::factory()->admin()->create();
        $regularUser = User::factory()->create();

        $this->assertTrue(Gate::forUser($administrator)->allows('import-herbarium-images'));
        $this->assertFalse(Gate::forUser($regularUser)->allows('import-herbarium-images'));
    }

    public function test_livewire_persists_authentication_verified_and_admin_middleware(): void
    {
        $middleware = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(Authenticate::class, $middleware);
        $this->assertContains(EnsureEmailIsVerified::class, $middleware);
        $this->assertContains(EnsureUserIsAdministrator::class, $middleware);
        $this->assertSame(1, count(array_keys($middleware, Authenticate::class, true)));
        $this->assertSame(1, count(array_keys($middleware, EnsureEmailIsVerified::class, true)));
        $this->assertSame(1, count(array_keys($middleware, EnsureUserIsAdministrator::class, true)));
    }
}
