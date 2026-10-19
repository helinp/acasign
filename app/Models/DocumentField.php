<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DocumentField extends Model
{
    use HasUuids;

    public const TYPES = ['date', 'checkbox', 'text'];

    /**
     * Taille par défaut (fraction de la page) de chaque type de champ.
     *
     * @var array<string, array{width: float, height: float}>
     */
    public const DEFAULT_SIZES = [
        'date' => ['width' => 0.16, 'height' => 0.03],
        'checkbox' => ['width' => 0.03, 'height' => 0.022],
        'text' => ['width' => 0.30, 'height' => 0.03],
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required' => 'boolean', 'added_by_signer' => 'boolean', 'filled_at' => 'datetime',
            'position_x' => 'float', 'position_y' => 'float', 'width' => 'float', 'height' => 'float',
        ];
    }

    /**
     * Règles de validation de la géométrie d'un champ (fractions de page).
     *
     * @return array<string, list<string>>
     */
    public static function geometryRules(int $pageCount): array
    {
        return [
            'page' => ['required', 'integer', 'min:1', 'max:'.$pageCount],
            'x' => ['required', 'numeric', 'between:0,0.98'],
            'y' => ['required', 'numeric', 'between:0,0.98'],
            'width' => ['required', 'numeric', 'between:0.015,0.8'],
            'height' => ['required', 'numeric', 'between:0.01,0.2'],
        ];
    }

    /**
     * Valeur imprimée dans le PDF (null si le champ est vide).
     */
    public function printableValue(): ?string
    {
        return match ($this->type) {
            'checkbox' => $this->value === '1' ? '1' : null,
            'date' => filled($this->value) ? Carbon::parse($this->value)->format('d/m/Y') : null,
            default => filled($this->value) ? $this->value : null,
        };
    }

    /**
     * Représentation transmise à l'éditeur JavaScript.
     *
     * @return array{id: string, type: string, label: ?string, required: bool, addedBySigner: bool, page: int, x: float, y: float, width: float, height: float, signer: ?string, value: ?string}
     */
    public function toEditorArray(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'label' => $this->label, 'required' => $this->required,
            'addedBySigner' => $this->added_by_signer, 'page' => $this->page,
            'x' => $this->position_x, 'y' => $this->position_y, 'width' => $this->width, 'height' => $this->height,
            'signer' => $this->signatureRequest?->signer_name, 'value' => $this->value,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }
}
