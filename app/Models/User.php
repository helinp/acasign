<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['entra_oid', 'tenant_id', 'name', 'first_name', 'last_name', 'email', 'job_title', 'notify_to_sign', 'notify_signed', 'role', 'can_send', 'signature_path', 'last_login_at', 'disabled_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function requestedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'requester_id');
    }

    public function signatureRequests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class, 'signer_id');
    }

    /**
     * Nom affiché sur la signature : « Prénom Nom » si renseignés dans le profil, sinon le nom Entra.
     */
    public function signatureName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: $this->name;
    }

    /**
     * Comptes actifs déjà connectés, proposés comme signataires ou délégataires.
     *
     * @return Collection<int, User>
     */
    public static function knownForSigning(): Collection
    {
        return static::query()->whereNull('disabled_at')->orderBy('name')->get(['id', 'name', 'first_name', 'last_name', 'email', 'job_title']);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'can_send' => 'boolean',
            'notify_to_sign' => 'boolean',
            'notify_signed' => 'boolean',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }
}
