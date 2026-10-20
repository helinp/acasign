<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SignatureRequest extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->verification_code ??= Str::random(48);
        });
    }

    protected function casts(): array
    {
        return [
            'display_options' => 'array', 'certificate_info' => 'array', 'signed_at' => 'datetime',
            'rejected_at' => 'datetime', 'last_reminded_at' => 'datetime',
            'position_x' => 'float', 'position_y' => 'float',
            'width' => 'float', 'height' => 'float',
        ];
    }

    /**
     * Lignes de texte imprimées dans le cartouche, selon les choix du signataire.
     *
     * @return list<string>
     */
    public function stampLines(\DateTimeInterface $signedAt): array
    {
        $options = $this->display_options ?? [];

        return array_values(array_filter([
            ($options['show_name'] ?? true) ? $this->signer_name : null,
            ($options['show_job_title'] ?? false) ? $this->signer_job_title : null,
            ($options['show_date'] ?? true) ? 'Signé le '.$signedAt->format('d/m/Y H:i') : null,
        ], fn (?string $line) => filled($line)));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentField::class);
    }

    public function delegatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'delegated_from_id');
    }
}
