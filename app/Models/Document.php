<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Document extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime', 'completed_at' => 'datetime',
            'cancelled_at' => 'datetime', 'revoked_at' => 'datetime',
            'page_sizes' => 'array',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function signatureRequests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentField::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * Nom du fichier proposé au téléchargement : suffixe « -signé » une fois toutes les signatures apposées.
     */
    public function downloadName(): string
    {
        $base = pathinfo((string) $this->original_name, PATHINFO_FILENAME) ?: Str::slug($this->title) ?: 'document';

        return $base.($this->status === 'signed' ? '-signé' : '').'.pdf';
    }

    public function usesEid(): bool
    {
        return $this->signature_method === 'eid';
    }

    /**
     * Autres documents du même lot, dans l'ordre d'envoi.
     *
     * @return HasMany<Document, $this>
     */
    public function batchSiblings(): HasMany
    {
        return $this->hasMany(self::class, 'batch_id', 'batch_id')->orderBy('created_at')->orderBy('title');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['signed', 'rejected', 'cancelled', 'expired', 'revoked'], true);
    }
}
