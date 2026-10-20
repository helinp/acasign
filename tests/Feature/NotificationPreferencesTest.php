<?php

namespace Tests\Feature;

use App\Jobs\DispatchDocumentNotification;
use App\Models\Document;
use App\Models\SignatureRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array',
            'services.teams.webhook_url' => null,
            'services.telegram' => ['bot_token' => null, 'chat_id' => null],
            'services.acanet.webhook_url' => null,
        ]);
        Http::fake();
    }

    public function test_user_can_turn_notifications_off_in_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), ['notify_to_sign' => 0, 'notify_signed' => 1])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->notify_to_sign);
        $this->assertTrue($user->notify_signed);
    }

    public function test_signers_who_opted_out_do_not_receive_the_signing_request(): void
    {
        $requester = User::factory()->create(['email' => 'rh@acarts.be']);
        User::factory()->create(['email' => 'refus@acarts.be', 'notify_to_sign' => false]);
        User::factory()->create(['email' => 'accord@acarts.be']);
        $document = $this->document($requester, ['refus@acarts.be', 'accord@acarts.be', 'sans-compte@acarts.be']);

        (new DispatchDocumentNotification($document->id, 'document.requested'))->handle();

        $this->assertEqualsCanonicalizing(['accord@acarts.be', 'sans-compte@acarts.be'], $this->recipients());
    }

    public function test_requester_who_opted_out_is_not_told_about_signatures(): void
    {
        $requester = User::factory()->create(['email' => 'rh@acarts.be', 'notify_signed' => false]);
        $document = $this->document($requester, ['prof@acarts.be']);

        (new DispatchDocumentNotification($document->id, 'signature.signed'))->handle();

        $this->assertSame([], $this->recipients());
    }

    public function test_rejection_is_always_sent_even_when_notifications_are_off(): void
    {
        $requester = User::factory()->create(['email' => 'rh@acarts.be', 'notify_signed' => false, 'notify_to_sign' => false]);
        $document = $this->document($requester, ['prof@acarts.be']);

        (new DispatchDocumentNotification($document->id, 'signature.rejected'))->handle();

        $this->assertSame(['rh@acarts.be'], $this->recipients());
    }

    /**
     * @param  list<string>  $signers
     */
    private function document(User $requester, array $signers): Document
    {
        $document = Document::create([
            'id' => (string) Str::uuid(), 'verification_code' => Str::random(48), 'requester_id' => $requester->id,
            'title' => 'Avenant', 'status' => 'pending', 'original_name' => 'avenant.pdf',
            'file_path' => 'documents/a/current.pdf', 'file_size' => 100, 'page_count' => 1,
            'sha256' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
        foreach ($signers as $email) {
            SignatureRequest::create(['document_id' => $document->id, 'signer_email' => $email, 'signer_name' => $email]);
        }

        return $document;
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()
            ->flatMap(fn ($message) => array_map(fn ($address) => $address->getAddress(), $message->getEnvelope()->getRecipients()))
            ->values()->all();
    }
}
