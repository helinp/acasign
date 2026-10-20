<?php

namespace App\Models;

use App\Support\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Réglages de l'application (identité de l'établissement, couleurs, contact…), stockés en clé/valeur et mis en cache.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'app-settings';

    /** Couleur des liens et actions par défaut (contraste 8:1 sur blanc). */
    public const DEFAULT_ACTION_COLOR = '#4B2AD6';

    /** Couleur du nom de l'établissement par défaut. */
    public const DEFAULT_BRAND_COLOR = '#F5006B';

    public const DEFAULT_SUBTITLE = 'Signature de documents';

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

    /** Nom court affiché dans l'en-tête (sigle, nom d'usage) ; à défaut, le nom complet. */
    public static function shortName(): string
    {
        return static::value('entity_short_name') ?: static::entityName();
    }

    public static function logoPath(): ?string
    {
        return static::value('logo_path');
    }

    /** Sous-titre affiché sous le nom de l'établissement. */
    public static function subtitle(): string
    {
        return static::value('app_subtitle') ?: self::DEFAULT_SUBTITLE;
    }

    public static function actionColor(): string
    {
        $color = static::value('action_color');

        return Color::isHex($color) ? strtoupper($color) : self::DEFAULT_ACTION_COLOR;
    }

    public static function brandColor(): string
    {
        $color = static::value('brand_color');

        return Color::isHex($color) ? strtoupper($color) : self::DEFAULT_BRAND_COLOR;
    }

    public static function contactEmail(): ?string
    {
        return static::value('contact_email');
    }

    public static function footerText(): ?string
    {
        return static::value('footer_text');
    }

    /** Domaine des adresses d'exemple : réglage, sinon premier domaine autorisé (ENTRA_ALLOWED_DOMAINS), sinon « exemple.be ». */
    public static function emailDomain(): string
    {
        return static::value('email_domain') ?: (config('services.entra.allowed_domains')[0] ?? 'exemple.be');
    }

    public static function emailPlaceholder(): string
    {
        return 'prenom.nom@'.static::emailDomain();
    }

    /** Variables CSS du thème personnalisé (vide si les couleurs par défaut sont conservées). */
    public static function themeCss(): string
    {
        $action = static::actionColor();
        $brand = static::brandColor();
        if ($action === self::DEFAULT_ACTION_COLOR && $brand === self::DEFAULT_BRAND_COLOR) {
            return '';
        }
        $hover = Color::darken($action, 0.2);

        return ':root{--ac-action:'.$action.';--ac-action-survol:'.$hover.';--ac-action-fond:'.Color::rgba($action, 0.08)
            .';--tblr-primary-rgb:'.Color::rgb($action).';--tblr-link-color-rgb:'.Color::rgb($action).';--tblr-link-hover-color-rgb:'.Color::rgb($hover)
            .';--ac-pink:'.$brand.'}';
    }

    /**
     * @return array<string, ?string>
     */
    private static function cachedValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }
}
