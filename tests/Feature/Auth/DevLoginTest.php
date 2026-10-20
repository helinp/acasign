<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DevUserSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DevLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Passer APP_ENV à « local » réactive la vérification CSRF, normalement coupée en tests.
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_dev_login_is_unavailable_outside_local_environment(): void
    {
        config(['app.dev_login_enabled' => true]);
        $this->seed(DevUserSeeder::class);

        $this->post('/dev/login', ['account' => 'admin'])->assertNotFound();
        $this->assertGuest();
        $this->get('/login')->assertDontSee('Développement uniquement');
    }

    public function test_dev_login_is_unavailable_when_flag_is_disabled(): void
    {
        $this->app['env'] = 'local';
        config(['app.dev_login_enabled' => false]);
        $this->seed(DevUserSeeder::class);

        $this->post('/dev/login', ['account' => 'admin'])->assertNotFound();
        $this->assertGuest();
    }

    public function test_seeded_admin_and_client_can_log_in_locally(): void
    {
        $this->app['env'] = 'local';
        config(['app.dev_login_enabled' => true]);
        $this->seed(DevUserSeeder::class);

        $this->get('/login')->assertSee('Développement uniquement');

        $this->post('/dev/login', ['account' => 'admin'])->assertRedirect(route('dashboard'));
        $admin = User::query()->where('email', DevUserSeeder::emailFor('admin'))->sole();
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->can_send);

        $this->post('/logout');
        $this->post('/dev/login', ['account' => 'client'])->assertRedirect(route('dashboard'));
        $client = User::query()->where('email', DevUserSeeder::emailFor('client'))->sole();
        $this->assertAuthenticatedAs($client);
        $this->assertFalse($client->isAdmin());
    }

    public function test_dev_login_rejects_arbitrary_accounts(): void
    {
        $this->app['env'] = 'local';
        config(['app.dev_login_enabled' => true]);
        User::factory()->create(['email' => 'direction@ecole.test', 'role' => 'admin']);

        $this->post('/dev/login', ['account' => 'direction@ecole.test'])->assertSessionHasErrors('account');
        $this->assertGuest();
    }
}
