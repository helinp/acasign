<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Réglages de l'application (nom de l'entité, logo…), stockés en clé/valeur et mis en cache.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'app-settings';

    public static function value(string $key, ?string $default = null): ?string
    {
        return static::cachedValues()[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    public static function entityName(): string
    {
        return static::value('entity_name') ?: (string) config('app.name');
    }

    /** Nom court affiché dans l'en-tête (ex. « ACARTS! ») ; à défaut, le nom complet. */
    public static function shortName(): string
    {
        return static::value('entity_short_name') ?: static::entityName();
    }

    public static function logoPath(): ?string
    {
        return static::value('logo_path');
    }

    /**
     * @return array<string, ?string>
     */
    private static function cachedValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }
}
