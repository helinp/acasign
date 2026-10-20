<?php

namespace App\Console\Commands;

use Firebase\JWT\JWT;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api:token {--ttl=3600 : Durée de validité en secondes}')]
#[Description('Génère un JWT temporaire pour tester l’API d’intégration')]
class GenerateApiToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $secret = config('services.api.jwt_secret');
        if (! $secret) {
            $this->error('API_JWT_SECRET n’est pas configuré.');

            return self::FAILURE;
        }
        $now = time();
        $this->line(JWT::encode([
            'iss' => config('services.api.jwt_issuer'), 'sub' => 'integration',
            'iat' => $now, 'nbf' => $now, 'exp' => $now + (int) $this->option('ttl'),
            'scope' => ['documents:write'], 'jti' => bin2hex(random_bytes(16)),
        ], $secret, 'HS256'));

        return self::SUCCESS;
    }
}
