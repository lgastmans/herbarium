<?php

namespace Tests\Feature;

use App\Livewire\Admin\CreateFamily;
use App\Livewire\Admin\DeleteFamily;
use App\Livewire\Admin\ViewHerbariumImage;
use App\Livewire\PublicSite\Catalogue;
use App\Models\Family;
use App\Models\Genus;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminPublicSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalogue_stays_read_only_for_guests_and_managers(): void
    {
        $this->specimen('PUBLIC-123');

        foreach ([null, User::factory()->create(), User::factory()->admin()->create()] as $user) {
            Auth::forgetGuards();
            if ($user) {
                $this->actingAs($user);
            }

            $this->get(route('public.home'))->assertOk()
                ->assertSee('PUBLIC-123')->assertSeeLivewire(Catalogue::class)
                ->assertDontSeeLivewire(\App\Livewire\Admin\HerbariumTable::class)
                ->assertDontSee('Add plant')->assertDontSee('openModal');
        }
    }

    public function test_public_search_is_independent_and_filters_records(): void
    {
        $this->specimen('PUBLIC-123');
        $this->specimen('OTHER-456');

        Livewire::test(Catalogue::class)->set('search', 'PUBLIC-123')
            ->assertSee('PUBLIC-123')->assertDontSee('OTHER-456');
    }

    public function test_all_management_routes_require_authentication_and_verification(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'admin.')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, $route->uri());
            if ($route->getName() !== 'admin.profile') {
                $this->assertContains('verified', $middleware, $route->uri());
            }
            $this->assertTrue($route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'));
        }

        foreach (['/admin', '/admin/plants/create', '/admin/families', '/admin/plants/replace-genus'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
        $this->post('/admin/genus-images')->assertRedirect(route('login'));
        $this->get('/admin/login')->assertOk();
        $this->assertSame('/admin/login', route('login', [], false));
    }

    public function test_old_management_urls_are_no_longer_exposed(): void
    {
        foreach (['/plants', '/plants/create', '/families', '/users', '/login', '/dashboard', '/upload-genus-image'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->post('/genus-images')->assertNotFound();
    }

    public function test_verified_regular_users_keep_management_access_but_cannot_import(): void
    {
        $specimen = $this->specimen('MANAGED-123');
        $this->actingAs(User::factory()->create());
        foreach (['/admin', '/admin/plants', '/admin/families', '/admin/genus', '/admin/places', '/admin/taluks', '/admin/districts', '/admin/states', '/admin/specifics', '/admin/statuses', '/admin/collectors', '/admin/users', '/admin/plants/create', '/admin/plants/update/'.$specimen->id, '/admin/plants/replace-genus', '/admin/plants/replace-family', '/admin/plants/replace-place', '/admin/genus-images', '/admin/profile'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get(route('admin.herbarium.images.import'))->assertForbidden();
    }

    public function test_unverified_users_can_fix_profile_but_cannot_open_management_pages(): void
    {
        $this->actingAs(User::factory()->unverified()->create());
        $this->get('/admin/families')->assertRedirect(route('verification.notice'));
        $this->get('/admin/profile')->assertOk();
        $this->get(route('verification.notice'))->assertOk();
        Livewire::test(CreateFamily::class)->assertForbidden();
    }

    public function test_modal_aliases_work_and_authorization_is_rechecked_on_update(): void
    {
        Livewire::test('admin.create-family')->assertForbidden();

        $this->actingAs(User::factory()->create());
        Livewire::test('admin.create-family')->set('family', 'Testaceae')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('families', ['family' => 'Testaceae']);

        $family = Family::where('family', 'Testaceae')->firstOrFail();
        $delete = Livewire::test(DeleteFamily::class)->set('id', $family->id);
        Auth::logout();
        $delete->call('delete')->assertForbidden();
        $this->assertDatabaseHas('families', ['id' => $family->id]);
    }

    public function test_image_deletion_is_scoped_to_the_opened_specimen(): void
    {
        Storage::fake('public');
        $first = $this->specimen('FIRST');
        $second = $this->specimen('SECOND');
        $image = HerbariumImages::create([
            'herbarium_id' => $second->id, 'genus_id' => $second->genus_id, 'filename' => 'other.png',
        ]);
        Storage::disk('public')->put('herbarium/other.png', 'image');
        $this->actingAs(User::factory()->create());
        try {
            Livewire::test(ViewHerbariumImage::class, ['herbarium' => $first])
                ->call('deleteImage', $image->id);
            $this->fail('An image belonging to another specimen must not be deleted.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(HerbariumImages::class, $exception->getModel());
        }
        $this->assertDatabaseHas('herbarium_images', ['id' => $image->id]);
        Storage::disk('public')->assertExists('herbarium/other.png');
    }

    public function test_login_and_logout_use_the_admin_and_public_destinations(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        Volt::test('admin.livewire.pages.auth.login')
            ->set('form.email', $user->email)->set('form.password', 'password')
            ->call('login')->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);

        Volt::test('admin.livewire.layout.navigation')->call('logout')
            ->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_password_reset_links_point_to_admin_and_tokens_still_work(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        Volt::test('admin.livewire.pages.auth.forgot-password')->set('email', $user->email)
            ->call('sendPasswordResetLink')->assertHasNoErrors();
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class,
            function ($notification) use ($user) {
                $url = $notification->toMail($user)->actionUrl;
                $this->assertStringContainsString('/admin/reset-password/', $url);
                $this->get($url)->assertOk();
                Volt::test('admin.livewire.pages.auth.reset-password', ['token' => $notification->token])
                    ->set('email', $user->email)->set('password', 'new-password')
                    ->set('password_confirmation', 'new-password')->call('resetPassword')
                    ->assertHasNoErrors()->assertRedirect(route('login'));

                return true;
            });
    }

    public function test_moved_modal_manager_resolves_components_and_model_arguments(): void
    {
        $this->actingAs(User::factory()->create());
        $specimen = $this->specimen('MODAL-123');
        Livewire::test(\LivewireUI\Modal\Modal::class)
            ->call('openModal', 'admin.edit-family', ['family' => $specimen->family_id])
            ->assertSeeLivewire(\App\Livewire\Admin\EditFamily::class);
        Livewire::test(\LivewireUI\Modal\Modal::class)
            ->call('openModal', 'admin.view-herbarium-image', ['herbarium' => $specimen->id])
            ->assertSeeLivewire(ViewHerbariumImage::class)
            ->assertSeeLivewire(\App\Livewire\Admin\UploadHerbariumImage::class);
    }

    public function test_email_verification_and_password_confirmation_stay_under_admin(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'password']);
        $this->actingAs($user);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify', now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
        $this->assertStringContainsString('/admin/verify-email/', $url);
        $this->get($url)->assertRedirect('/admin?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get(route('password.confirm'))->assertOk();
        Volt::test('admin.livewire.pages.auth.confirm-password')
            ->set('password', 'password')->call('confirmPassword')
            ->assertHasNoErrors()->assertRedirect('/admin');
    }

    public function test_moved_label_export_and_image_viewer_render(): void
    {
        $this->actingAs(User::factory()->create());
        $specimen = $this->specimen('LABEL-123');
        $image = HerbariumImages::create([
            'herbarium_id' => $specimen->id, 'genus_id' => $specimen->genus_id,
            'filename' => 'label.png',
        ]);
        $this->get(route('admin.herbarium.image.view', $image))->assertOk()->assertSee('LABEL-123');
        $this->get(route('admin.herbarium-label-view', $specimen->id))->assertOk()->assertSee('LABEL-123');
        Livewire::test(\App\Livewire\Admin\HerbariumTable::class)
            ->call('exportPdf', $specimen->id)->assertFileDownloaded('herbarium-label-LABEL-123.pdf');
    }

    public function test_genus_uploads_use_the_moved_routes_and_components(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $specimen = $this->specimen('GENUS-123');
        $this->post(route('admin.genus-images.store'), [
            'genus_id' => $specimen->genus_id,
            'image' => \Illuminate\Http\UploadedFile::fake()->image('a-long-original-genus-image-filename.png'),
        ])->assertRedirect(route('admin.genus-images.index'));
        Livewire::test(\App\Livewire\Admin\UploadGenusImage::class, ['genus_id' => $specimen->genus_id])
            ->set('photo', \Illuminate\Http\UploadedFile::fake()->image('another-image.png'))
            ->call('save')->assertHasNoErrors()->assertDispatched('refreshGenusImageTable');

        $images = \App\Models\GenusImage::all();
        $this->assertCount(2, $images);
        foreach ($images as $image) {
            $this->assertSame($specimen->genus_id, $image->genus_id);
            $this->assertLessThanOrEqual(32, strlen($image->filename));
            Storage::disk('public')->assertExists('photos/'.$image->filename);
        }
    }

    private function specimen(string $number): Herbarium
    {
        $family = Family::firstOrCreate(['family' => 'Acanthaceae']);
        $genus = Genus::firstOrCreate(['name' => 'Barleria']);

        return Herbarium::create([
            'family_id' => $family->id, 'genus_id' => $genus->id,
            'collection_number' => $number,
        ]);
    }
}
