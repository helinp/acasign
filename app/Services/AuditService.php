<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditService
{
    public function record(string $event, ?Document $document = null, ?User $actor = null, array $payload = [], ?Request $request = null): AuditEvent
    {
        return DB::transaction(function () use ($event, $document, $actor, $payload, $request) {
            $previous = AuditEvent::query()->lockForUpdate()->latest('created_at')->latest('id')->first()?->hash;
            $timestamp = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            ksort($payload);
            $canonical = json_encode([
                'document_id' => $document?->id,
                'actor_id' => $actor?->id,
                'event' => $event,
                'payload' => $payload,
                'timestamp' => $timestamp,
                'previous_hash' => $previous,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return AuditEvent::create([
                'document_id' => $document?->id,
                'actor_id' => $actor?->id,
                'event' => $event,
                'payload' => $payload,
                'ip_address' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 1000),
                'session_id' => $request?->hasSession() ? $request->session()->getId() : null,
                'previous_hash' => $previous,
                'hash' => hash('sha256', $canonical),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    public function verifyChain(): bool
    {
        $previous = null;
        foreach (AuditEvent::query()->oldest('created_at')->oldest('id')->cursor() as $event) {
            $payload = $event->payload ?? [];
            ksort($payload);
            $canonical = json_encode([
                'document_id' => $event->document_id,
                'actor_id' => $event->actor_id,
                'event' => $event->event,
                'payload' => $payload,
                'timestamp' => $event->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'previous_hash' => $previous,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($event->previous_hash !== $previous || ! hash_equals($event->hash, hash('sha256', $canonical))) {
                return false;
            }
            $previous = $event->hash;
        }

        return true;
    }
}
