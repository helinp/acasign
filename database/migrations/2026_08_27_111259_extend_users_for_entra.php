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
            $table->uuid('entra_oid')->nullable()->unique()->after('id');
            $table->uuid('tenant_id')->nullable()->index()->after('entra_oid');
            $table->string('job_title')->nullable()->after('email');
            $table->string('role')->default('user')->index();
            $table->boolean('can_send')->default(false)->index();
            $table->string('signature_path')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'entra_oid', 'tenant_id', 'job_title', 'role', 'can_send',
                'signature_path', 'last_login_at', 'disabled_at',
            ]);
        });
    }
};
