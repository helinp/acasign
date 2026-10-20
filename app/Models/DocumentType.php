<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $fillable = ['name', 'is_active', 'requires_eid'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_eid' => 'boolean'];
    }

    /**
     * @param  Builder<DocumentType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
