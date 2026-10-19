<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class InternalIdentityService
{
    public function normalizeAndValidate(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $fragment = mb_strtolower((string) config('services.entra.forbidden_username_fragment'));
        $domain = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : '';
        $allowed = array_map('strtolower', config('services.entra.allowed_domains', []));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || str_contains($email, '#ext#') || ($fragment && str_contains($email, $fragment))) {
            throw ValidationException::withMessages(['email' => 'Ce compte ne peut pas utiliser le service de signature.']);
        }
        if ($allowed && ! in_array($domain, $allowed, true)) {
            throw ValidationException::withMessages(['email' => 'Le domaine de ce compte n’est pas autorisé.']);
        }

        return $email;
    }
}
