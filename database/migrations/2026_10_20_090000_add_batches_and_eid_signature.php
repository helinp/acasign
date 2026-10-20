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
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('requires_eid')->default(false)->after('is_active');
        });

        Schema::table('documents', function (Blueprint $table) {
            // Lot : plusieurs PDF envoyés ensemble aux mêmes signataires.
            $table->uuid('batch_id')->nullable()->index()->after('id');
            // « internal » (signature interne + scellement) ou « eid » (signature qualifiée par carte eID, via DSS).
            $table->string('signature_method', 16)->default('internal')->after('document_type');
            // Taille de chaque page en points PDF : [[largeur, hauteur], ...] (placement de la signature eID).
            $table->json('page_sizes')->nullable()->after('page_count');
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            // Identité lue dans le certificat eID au moment de la signature.
            $table->json('certificate_info')->nullable()->after('document_sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn('certificate_info');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['batch_id', 'signature_method', 'page_sizes']);
        });

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('requires_eid');
        });
    }
};
