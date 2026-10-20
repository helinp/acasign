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
            'entity_name' => 'Académie des Arts du Val',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Académie des Arts du Val', Setting::entityName());
        Storage::disk('local')->assertExists(Setting::logoPath());

        auth()->logout();
        $this->get(route('login'))->assertSee('Académie des Arts du Val')->assertSee(route('branding.logo'), false);
        $this->get(route('branding.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_short_name_is_used_in_the_header_and_full_name_on_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'entity_name' => 'Académie intercommunale des Arts du Val',
            'entity_short_name' => 'ADAV!',
        ])->assertSessionHasNoErrors();

        $this->assertSame('ADAV!', Setting::shortName());
        $this->actingAs($admin)->get(route('dashboard'))->assertSee('<title>Accueil · ADAV!</title>', false);
        auth()->logout();
        $this->get(route('login'))->assertSee('Académie intercommunale des Arts du Val');
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

    public function test_admin_customizes_subtitle_colors_contact_and_footer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'app_subtitle' => 'Parapheur numérique',
            'action_color' => '#0b5394',
            'brand_color' => '#C2185B',
            'email_domain' => 'arts-du-val.be',
            'contact_email' => 'secretariat@arts-du-val.be',
            'footer_text' => 'Données traitées par le pouvoir organisateur.',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('documents.create'))->assertOk()
            ->assertSee('Parapheur numérique')
            ->assertSee('--ac-action:#0B5394', false)
            ->assertSee('--ac-pink:#C2185B', false)
            ->assertSee('placeholder="prenom.nom@arts-du-val.be"', false)
            ->assertSee('mailto:secretariat@arts-du-val.be', false)
            ->assertSee('Données traitées par le pouvoir organisateur.');
    }

    public function test_too_light_action_color_is_refused_and_colors_can_be_reset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.update'), ['action_color' => '#FFD400'])->assertSessionHasErrors('action_color');
        $this->assertSame(Setting::DEFAULT_ACTION_COLOR, Setting::actionColor());

        $this->actingAs($admin)->put(route('admin.settings.update'), ['action_color' => '#0B5394'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.settings.update'), ['action_color' => '#0B5394', 'reset_colors' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(Setting::DEFAULT_ACTION_COLOR, Setting::actionColor());
        $this->assertSame('', Setting::themeCss());
    }

    public function test_admin_sends_a_test_email(): void
    {
        config(['mail.default' => 'array']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.settings.mail-test'), ['test_recipient' => 'secretariat@ecole.test'])
            ->assertRedirect()->assertSessionHas('success');

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('secretariat@ecole.test', $messages->first()->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_failed_test_email_shows_the_error(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.url' => null]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.settings.mail-test'), ['test_recipient' => 'secretariat@ecole.test'])
            ->assertRedirect()->assertSessionHas('mail_test_error');
    }

    public function test_non_admin_cannot_change_settings(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->put(route('admin.settings.update'), ['entity_name' => 'Pirate'])->assertForbidden();
        $this->actingAs($user)->post(route('admin.settings.mail-test'), ['test_recipient' => 'x@ecole.test'])->assertForbidden();
        $this->assertNotSame('Pirate', Setting::entityName());
    }
}
