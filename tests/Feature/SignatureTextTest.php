<?php

namespace Tests\Feature;

use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\EncryptedSignatureStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SignatureTextTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_stamp_lines_follow_signer_choices(): void
    {
        $signedAt = new \DateTimeImmutable('2026-10-19 14:30');
        $request = new SignatureRequest(['signer_name' => 'Marie Dupont', 'signer_job_title' => 'Directrice']);

        $request->display_options = ['show_name' => true, 'show_job_title' => true, 'show_date' => true];
        $this->assertSame(['Marie Dupont', 'Directrice', 'Signé le 19/10/2026 14:30'], $request->stampLines($signedAt));

        $request->display_options = ['show_name' => true, 'show_job_title' => false, 'show_date' => false];
        $this->assertSame(['Marie Dupont'], $request->stampLines($signedAt));
    }

    public function test_job_title_line_is_skipped_when_empty(): void
    {
        $request = new SignatureRequest(['signer_name' => 'Marie Dupont', 'display_options' => ['show_name' => true, 'show_job_title' => true, 'show_date' => false]]);

        $this->assertSame(['Marie Dupont'], $request->stampLines(now()));
    }

    public function test_user_can_set_first_name_last_name_and_job_title(): void
    {
        $user = User::factory()->create(['name' => 'DUPONT Marie (ACARTS)']);

        $this->actingAs($user)->put(route('profile.update'), ['first_name' => 'Marie', 'last_name' => 'Dupont', 'job_title' => 'Directrice'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Marie Dupont', $user->signatureName());
        $this->assertSame('Directrice', $user->job_title);
    }

    public function test_signature_name_falls_back_to_entra_name(): void
    {
        $this->assertSame('Marie Dupont', (new User(['name' => 'Marie Dupont']))->signatureName());
    }

    public function test_user_can_view_own_signature_image(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $user->update(['signature_path' => app(EncryptedSignatureStorage::class)->store($user, UploadedFile::fake()->image('signature.png', 40, 20))]);

        $this->actingAs($user)->get(route('profile.signature.show'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->actingAs($user)->get(route('profile.show'))->assertSee(route('profile.signature.show'), false);
    }

    public function test_signature_image_is_missing_without_upload(): void
    {
        $this->actingAs(User::factory()->create())->get(route('profile.signature.show'))->assertNotFound();
    }
}
