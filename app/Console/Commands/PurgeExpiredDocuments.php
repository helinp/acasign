<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDocumentNotification;
use App\Models\Document;
use App\Services\AuditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('documents:maintain')]
#[Description('Expire les demandes, envoie les relances et purge les PDF arrivés à rétention')]
class PurgeExpiredDocuments extends Command
{
    public function handle(): int
    {
        $audit = app(AuditService::class);
        Document::where('status', 'pending')->where('expires_at', '<', now())->each(function (Document $document) use ($audit) {
            $document->update(['status' => 'expired']);
            $audit->record('document.expired', $document, null);
            DispatchDocumentNotification::dispatch($document->id, 'document.expired');
        });

        Document::where('status', 'pending')->whereBetween('expires_at', [now(), now()->addDays(3)])
            ->whereHas('signatureRequests', fn ($q) => $q->where('status', 'pending')->where(fn ($r) => $r->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<', now()->subDay())))
            ->each(function (Document $document) use ($audit) {
                $document->signatureRequests()->where('status', 'pending')->update(['last_reminded_at' => now()]);
                $audit->record('document.reminded', $document, null);
                DispatchDocumentNotification::dispatch($document->id, 'document.requested');
            });

        $retentionDays = (int) config('services.signature.retention_days');
        $cutoff = now()->subDays($retentionDays);
        Document::whereNotNull('completed_at')->where('completed_at', '<', $cutoff)->each(function (Document $document) use ($audit) {
            if (Storage::disk('local')->exists($document->file_path)) {
                Storage::disk('local')->delete($document->file_path);
                $audit->record('document.file.purged', $document, null, ['retention_days' => (int) config('services.signature.retention_days')]);
            }
        });

        return self::SUCCESS;
    }
}
