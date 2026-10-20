<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Préférences d'e-mail : document reçu à signer, et signature apposée sur un document envoyé.
            $table->boolean('notify_to_sign')->default(true)->after('job_title');
            $table->boolean('notify_signed')->default(true)->after('notify_to_sign');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_to_sign', 'notify_signed']);
        });
    }
};
