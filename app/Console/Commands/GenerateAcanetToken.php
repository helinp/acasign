<?php

namespace App\Console\Commands;

use Firebase\JWT\JWT;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('acanet:token {--ttl=3600 : Durée de validité en secondes}')]
#[Description('Génère un JWT temporaire pour tester l’API Acanet')]
class GenerateAcanetToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $secret = config('services.acanet.jwt_secret');
        if (! $secret) {
            $this->error('ACANET_JWT_SECRET n’est pas configuré.');

            return self::FAILURE;
        }
        $now = time();
        $this->line(JWT::encode([
            'iss' => config('services.acanet.jwt_issuer'), 'sub' => 'acanet',
            'iat' => $now, 'nbf' => $now, 'exp' => $now + (int) $this->option('ttl'),
            'scope' => ['documents:write'], 'jti' => bin2hex(random_bytes(16)),
        ], $secret, 'HS256'));

        return self::SUCCESS;
    }
}
