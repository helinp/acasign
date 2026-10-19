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
     * @var array<string, array{name: string, first_name: string, last_name: string, job_title: string, email: string, role: string, can_send: bool}>
     */
    public const ACCOUNTS = [
        'admin' => ['name' => 'Admin Démo', 'first_name' => 'Admin', 'last_name' => 'Démo', 'job_title' => 'Directeur', 'email' => 'dev.admin@acarts.be', 'role' => 'admin', 'can_send' => true],
        'client' => ['name' => 'Client Démo', 'first_name' => 'Client', 'last_name' => 'Démo', 'job_title' => 'Professeur', 'email' => 'dev.client@acarts.be', 'role' => 'user', 'can_send' => false],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $account) {
            User::query()->updateOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'first_name' => $account['first_name'], 'last_name' => $account['last_name'], 'job_title' => $account['job_title'], 'role' => $account['role'], 'can_send' => $account['can_send'], 'disabled_at' => null],
            );
        }
    }
}
