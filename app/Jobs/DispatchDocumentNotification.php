<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        foreach ($recipients as $email) {
            try {
                Mail::raw($body, fn ($mail) => $mail->to($email)->subject($subject));
            } catch (\Throwable $e) {
                Log::warning('Email de signature non envoyé', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            }
        }

        $teams = config('services.teams.webhook_url');
        if ($teams) {
            Http::connectTimeout(3)->timeout(10)->post($teams, ['text' => "**$subject**\n\n$body"])->throw();
        }
        $telegram = config('services.telegram');
        if ($telegram['bot_token'] && $telegram['chat_id']) {
            Http::connectTimeout(3)->timeout(10)->post("https://api.telegram.org/bot{$telegram['bot_token']}/sendMessage", ['chat_id' => $telegram['chat_id'], 'text' => "$subject\n\n$body"])->throw();
        }

        if ($url = config('services.acanet.webhook_url')) {
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
                    'X-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$json, (string) config('services.acanet.webhook_secret')),
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

    private function message(Document $document): array
    {
        $link = route('documents.show', $document);

        return match ($this->event) {
            'document.requested', 'signature.delegated' => [
                $document->signatureRequests->where('status', 'pending')->pluck('signer_email')->unique()->all(),
                'Document à signer : '.$document->title,
                "Une signature vous est demandée avant le {$document->expires_at?->format('d/m/Y')}.\n$link",
            ],
            'signature.rejected' => [[$document->requester->email], 'Signature refusée : '.$document->title, "Un signataire a refusé le document.\n$link"],
            'document.cancelled' => [$document->signatureRequests->pluck('signer_email')->unique()->all(), 'Demande annulée : '.$document->title, "Le demandeur a annulé cette demande.\n$link"],
            'document.revoked' => [array_values(array_unique([$document->requester->email, ...$document->signatureRequests->pluck('signer_email')->all()])), 'Document révoqué : '.$document->title, "Ce document signé a été révoqué.\n$link"],
            default => [[$document->requester->email], 'Document signé : '.$document->title, "Une signature a été apposée. État actuel : {$document->status}.\n$link"],
        };
    }
}
