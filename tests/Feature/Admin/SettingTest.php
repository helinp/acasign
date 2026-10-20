<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sets_entity_name_and_logo_shown_on_login_page(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'entity_name' => 'Académie ACARTS!',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Académie ACARTS!', Setting::entityName());
        Storage::disk('local')->assertExists(Setting::logoPath());

        auth()->logout();
        $this->get(route('login'))->assertSee('Académie ACARTS!')->assertSee(route('branding.logo'), false);
        $this->get(route('branding.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_short_name_is_used_in_the_header_and_full_name_on_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'entity_name' => 'Académie intercommunale de Court-Saint-Étienne',
            'entity_short_name' => 'ACARTS!',
        ])->assertSessionHasNoErrors();

        $this->assertSame('ACARTS!', Setting::shortName());
        $this->actingAs($admin)->get(route('dashboard'))->assertSee('<title>Accueil · ACARTS!</title>', false);
        auth()->logout();
        $this->get(route('login'))->assertSee('Académie intercommunale de Court-Saint-Étienne');
    }

    public function test_logo_can_be_removed(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.settings.update'), ['logo' => UploadedFile::fake()->image('logo.png')]);
        $path = Setting::logoPath();

        $this->actingAs($admin)->put(route('admin.settings.update'), ['remove_logo' => 1])->assertSessionHasNoErrors();

        $this->assertNull(Setting::logoPath());
        Storage::disk('local')->assertMissing($path);
        $this->get(route('branding.logo'))->assertNotFound();
    }

    public function test_svg_logo_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('logo');
    }

    public function test_non_admin_cannot_change_settings(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->put(route('admin.settings.update'), ['entity_name' => 'Pirate'])->assertForbidden();
        $this->assertNotSame('Pirate', Setting::entityName());
    }
}
