<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
            'signer_can_move' => 'boolean',
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

    /**
     * Documents envoyés par l'utilisateur ou qui lui sont adressés pour signature ; $role restreint à l'un des deux.
     */
    public function scopeInvolving(Builder $query, User $user, ?string $role = null): void
    {
        $email = mb_strtolower($user->email);
        $asSigner = fn (Builder $signatures) => $signatures->where('signer_email', $email);
        $query->where(fn (Builder $documents) => match ($role) {
            'requester' => $documents->where('requester_id', $user->id),
            'signer' => $documents->whereHas('signatureRequests', $asSigner),
            default => $documents->where('requester_id', $user->id)->orWhereHas('signatureRequests', $asSigner),
        });
    }

    /**
     * Recherche par titre, personne (demandeur ou signataire : nom ou e-mail), type et période (sur $dateColumn).
     *
     * @param  array{q?: string, person?: string, type?: string, period?: int|string, from?: string, to?: string}  $filters
     */
    public function scopeFiltered(Builder $query, array $filters, string $dateColumn = 'created_at'): void
    {
        $like = fn (string $value): string => '%'.mb_strtolower($value).'%';
        $query
            ->when($filters['q'] ?? null, fn (Builder $documents, string $text) => $documents->whereRaw('LOWER(title) LIKE ?', [$like($text)]))
            ->when($filters['person'] ?? null, fn (Builder $documents, string $person) => $documents->where(fn (Builder $documents) => $documents
                ->whereHas('requester', fn (Builder $users) => $users->whereRaw('LOWER(name) LIKE ?', [$like($person)])->orWhereRaw('LOWER(email) LIKE ?', [$like($person)]))
                ->orWhereHas('signatureRequests', fn (Builder $signatures) => $signatures->whereRaw('LOWER(signer_name) LIKE ?', [$like($person)])->orWhereRaw('LOWER(signer_email) LIKE ?', [$like($person)]))))
            ->when($filters['type'] ?? null, fn (Builder $documents, string $type) => $documents->where('document_type', $type))
            ->when($filters['period'] ?? null, fn (Builder $documents, int|string $days) => $documents->where($dateColumn, '>=', now()->subDays((int) $days)->startOfDay()))
            ->when($filters['from'] ?? null, fn (Builder $documents, string $from) => $documents->where($dateColumn, '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $documents, string $to) => $documents->where($dateColumn, '<=', Carbon::parse($to)->endOfDay()));
    }

    /**
     * Tri de la liste : récent / ancien (sur $dateColumn), titre, échéance, demandeur.
     */
    public function scopeSorted(Builder $query, string $sort, string $dateColumn = 'created_at'): void
    {
        match ($sort) {
            'oldest' => $query->orderBy($dateColumn),
            'title' => $query->orderBy('title'),
            'title_desc' => $query->orderByDesc('title'),
            'deadline' => $query->orderBy('expires_at'),
            'requester' => $query->orderBy(User::select('name')->whereColumn('users.id', 'documents.requester_id'))->orderByDesc($dateColumn),
            default => $query->orderByDesc($dateColumn),
        };
    }
}
