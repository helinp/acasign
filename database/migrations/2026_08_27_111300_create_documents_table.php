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
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('verification_code', 64)->unique();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('external_reference')->nullable()->unique();
            $table->string('document_type')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('original_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->unsignedSmallInteger('page_count');
            $table->char('sha256', 64);
            $table->char('sealed_sha256', 64)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['requester_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
