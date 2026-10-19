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
        Schema::create('document_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('signature_request_id')->constrained('signature_requests')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('label', 80)->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('added_by_signer')->default(false);
            $table->unsignedSmallInteger('page')->default(1);
            $table->decimal('position_x', 8, 5);
            $table->decimal('position_y', 8, 5);
            $table->decimal('width', 8, 5);
            $table->decimal('height', 8, 5);
            $table->text('value')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();
            $table->index(['signature_request_id', 'page']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_fields');
    }
};
