<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtres de la liste des documents, partagés par la liste, l'export CSV et le téléchargement groupé (ZIP).
 */
class DocumentFilterRequest extends FormRequest
{
    /** Onglets de la liste et statuts qu'ils regroupent. */
    public const TABS = [
        'signed' => ['signed'],
        'pending' => ['pending'],
        'closed' => ['rejected', 'cancelled', 'expired', 'revoked'],
    ];

    public const SORTS = ['recent', 'oldest', 'title', 'title_desc', 'deadline', 'requester'];

    /** Raccourcis de période, en jours. */
    public const PERIODS = [7, 30, 90, 365];

    public const PER_PAGE = [25, 50, 100];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'person' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:80'],
            'role' => ['nullable', 'in:requester,signer'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::TABS))],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)],
            'period' => ['nullable', 'integer', 'in:'.implode(',', self::PERIODS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'in:'.implode(',', self::PER_PAGE)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from' => 'date de début', 'to' => 'date de fin', 'person' => 'personne'];
    }

    public function tab(): string
    {
        return $this->validated('status') ?? 'signed';
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'recent';
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::PER_PAGE[0]);
    }

    /**
     * Filtres actifs (hors onglet, tri et pagination), pour les liens et les étiquettes « filtre actif ».
     *
     * @return array<string, string>
     */
    public function activeFilters(): array
    {
        return array_filter($this->safe()->only(['q', 'person', 'type', 'role', 'period', 'from', 'to']), fn ($value) => filled($value));
    }
}
