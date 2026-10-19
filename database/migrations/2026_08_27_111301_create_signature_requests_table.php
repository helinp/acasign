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
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('verification_code', 64)->unique();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('signer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('delegated_from_id')->nullable();
            $table->string('signer_email')->index();
            $table->string('signer_name')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('page')->default(1);
            $table->decimal('position_x', 8, 5)->default(0.60);
            $table->decimal('position_y', 8, 5)->default(0.72);
            $table->decimal('width', 8, 5)->default(0.28);
            $table->decimal('height', 8, 5)->default(0.12);
            $table->json('display_options')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'signer_email']);
            $table->index(['signer_email', 'status']);
        });

        // Auto-référence : la contrainte doit être ajoutée après la création
        // de la clé primaire (sinon PostgreSQL refuse la FK, SQLSTATE 42830).
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->foreign('delegated_from_id')
                ->references('id')->on('signature_requests')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signature_requests');
    }
};
