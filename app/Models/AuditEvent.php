<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit events are immutable.'));
    }

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * Libellés lisibles des événements du journal de preuve.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'document.created' => 'Demande créée',
        'api.document.created' => 'Demande créée (Acanet)',
        'document.placements.updated' => 'Placement des signatures modifié',
        'document.field.created' => 'Champ à compléter ajouté',
        'document.field.deleted' => 'Champ à compléter retiré',
        'document.viewed' => 'Fiche consultée',
        'document.downloaded' => 'PDF ouvert ou téléchargé',
        'document.reminded' => 'Rappel envoyé',
        'document.completed' => 'Document entièrement signé',
        'document.cancelled' => 'Demande annulée',
        'api.document.cancelled' => 'Demande annulée (Acanet)',
        'document.revoked' => 'Document révoqué',
        'api.document.revoked' => 'Document révoqué (Acanet)',
        'document.expired' => 'Demande expirée',
        'document.file.purged' => 'Fichier supprimé (fin de conservation)',
        'signature.signed' => 'Signé',
        'signature.rejected' => 'Signature refusée',
        'signature.delegated' => 'Signature déléguée',
        'signature.position.updated' => 'Cadre de signature déplacé',
        'signature.field.created' => 'Champ ajouté par le signataire',
    ];

    /** Consultations : regroupées et repliées dans le journal. */
    private const ROUTINE = ['document.viewed', 'document.downloaded'];

    public function label(): string
    {
        $label = self::LABELS[$this->event] ?? ucfirst(str_replace(['.', '_'], ' ', $this->event));
        if ($this->event === 'signature.signed') {
            $label .= ($this->payload['method'] ?? null) === 'eid' ? ' avec la carte eID' : ' (signature interne)';
        }

        return $label;
    }

    public function isRoutine(): bool
    {
        return in_array($this->event, self::ROUTINE, true);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
