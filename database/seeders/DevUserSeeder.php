<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Comptes de démonstration pour le développement local (sans Entra).
 */
class DevUserSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, first_name: string, last_name: string, job_title: string, role: string, can_send: bool}>
     */
    public const ACCOUNTS = [
        'admin' => ['name' => 'Admin Démo', 'first_name' => 'Admin', 'last_name' => 'Démo', 'job_title' => 'Directeur', 'role' => 'admin', 'can_send' => true],
        'client' => ['name' => 'Client Démo', 'first_name' => 'Client', 'last_name' => 'Démo', 'job_title' => 'Professeur', 'role' => 'user', 'can_send' => false],
    ];

    /** Adresse du compte de démonstration, dans le premier domaine autorisé (ENTRA_ALLOWED_DOMAINS) ou « demo.test ». */
    public static function emailFor(string $account): string
    {
        return 'dev.'.$account.'@'.(config('services.entra.allowed_domains')[0] ?? 'demo.test');
    }

    public function run(): void
    {
        foreach (self::ACCOUNTS as $key => $account) {
            User::query()->updateOrCreate(
                ['email' => self::emailFor($key)],
                ['name' => $account['name'], 'first_name' => $account['first_name'], 'last_name' => $account['last_name'], 'job_title' => $account['job_title'], 'role' => $account['role'], 'can_send' => $account['can_send'], 'disabled_at' => null],
            );
        }
    }
}
