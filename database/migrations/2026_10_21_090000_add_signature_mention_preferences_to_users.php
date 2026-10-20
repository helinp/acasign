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
            // Mentions cochées par défaut sous la signature : nom, qualité, date.
            $table->boolean('sign_show_name')->default(true)->after('notify_signed');
            $table->boolean('sign_show_job_title')->default(true)->after('sign_show_name');
            $table->boolean('sign_show_date')->default(true)->after('sign_show_job_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sign_show_name', 'sign_show_job_title', 'sign_show_date']);
        });
    }
};
