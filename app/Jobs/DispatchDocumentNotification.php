<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DispatchDocumentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public array $backoff = [60, 300, 1800];

    public function __construct(public string $documentId, public string $event) {}

    public function handle(): void
    {
        $document = Document::with(['requester', 'signatureRequests'])->find($this->documentId);
        if (! $document) {
            return;
        }

        [$recipients, $subject, $body] = $this->message($document);
        foreach ($this->acceptingRecipients($recipients) as $email) {
            try {
                $personal = str_replace(':lien', $this->linkFor($document, $email), $body);
                Mail::raw($personal, fn ($mail) => $mail->to($email)->subject($subject));
            } catch (\Throwable $e) {
                Log::warning('Email de signature non envoyé', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            }
        }

        $body = str_replace(':lien', route('documents.show', $document), $body);
        $teams = config('services.teams.webhook_url');
        if ($teams) {
            Http::connectTimeout(3)->timeout(10)->post($teams, ['text' => "**$subject**\n\n$body"])->throw();
        }
        $telegram = config('services.telegram');
        if ($telegram['bot_token'] && $telegram['chat_id']) {
            Http::connectTimeout(3)->timeout(10)->post("https://api.telegram.org/bot{$telegram['bot_token']}/sendMessage", ['chat_id' => $telegram['chat_id'], 'text' => "$subject\n\n$body"])->throw();
        }

        if ($url = config('services.api.webhook_url')) {
            $payload = [
                'event' => $this->event, 'document_id' => $document->id,
                'external_reference' => $document->external_reference, 'status' => $document->status,
                'occurred_at' => now()->utc()->toIso8601String(),
            ];
            $delivery = WebhookDelivery::create(['document_id' => $document->id, 'event' => $this->event, 'url' => $url, 'payload' => $payload]);
            try {
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $timestamp = (string) now()->timestamp;
                $response = Http::connectTimeout(3)->timeout(10)->retry(3, 500)->withHeaders([
                    'X-Signature-Timestamp' => $timestamp,
                    'X-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$json, (string) config('services.api.webhook_secret')),
                    'Idempotency-Key' => $delivery->id,
                ])->withBody($json, 'application/json')->post($url);
                $delivery->update(['attempts' => 1, 'last_status' => $response->status(), 'delivered_at' => $response->successful() ? now() : null, 'last_error' => $response->successful() ? null : $response->body()]);
                $response->throw();
            } catch (\Throwable $e) {
                $delivery->update(['attempts' => $delivery->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
                throw $e;
            }
        }
    }

    /**
     * Lien personnel : la page de signature du destinataire s'il doit signer, sinon la fiche du document.
     */
    private function linkFor(Document $document, string $email): string
    {
        $request = $document->signatureRequests
            ->where('status', 'pending')
            ->first(fn ($signatureRequest) => mb_strtolower($signatureRequest->signer_email) === mb_strtolower($email));

        return $request ? route('signatures.show', $request) : route('documents.show', $document);
    }

    /**
     * Retire les destinataires qui ont refusé ce type d'e-mail dans leur profil.
     * Les adresses sans compte reçoivent toujours le message (elles n'ont pas de préférence),
     * et les refus, annulations et révocations sont toujours envoyés.
     *
     * @param  list<string>  $recipients
     * @return list<string>
     */
    private function acceptingRecipients(array $recipients): array
    {
        $preference = match ($this->event) {
            'document.requested', 'signature.delegated' => 'notify_to_sign',
            'signature.signed' => 'notify_signed',
            default => null,
        };
        if ($preference === null || $recipients === []) {
            return $recipients;
        }
        $optedOut = User::query()
            ->whereIn(DB::raw('LOWER(email)'), array_map('mb_strtolower', $recipients))
            ->where($preference, false)
            ->pluck('email')
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();

        return array_values(array_filter($recipients, fn (string $email) => ! in_array(mb_strtolower($email), $optedOut, true)));
    }

    private function message(Document $document): array
    {
        $link = route('documents.show', $document);

        return match ($this->event) {
            'document.requested', 'signature.delegated' => [
                $document->signatureRequests->where('status', 'pending')->pluck('signer_email')->unique()->values()->all(),
                'Document à signer : '.$document->title,
                "Une signature vous est demandée avant le {$document->expires_at?->format('d/m/Y')}.\n:lien",
            ],
            'signature.rejected' => [[$document->requester->email], 'Signature refusée : '.$document->title, "Un signataire a refusé le document.\n$link"],
            'document.cancelled' => [$document->signatureRequests->pluck('signer_email')->unique()->all(), 'Demande annulée : '.$document->title, "Le demandeur a annulé cette demande.\n$link"],
            'document.revoked' => [array_values(array_unique([$document->requester->email, ...$document->signatureRequests->pluck('signer_email')->all()])), 'Document révoqué : '.$document->title, "Ce document signé a été révoqué.\n$link"],
            default => [[$document->requester->email], 'Document signé : '.$document->title, "Une signature a été apposée. État actuel : {$document->status}.\n$link"],
        };
    }
}
