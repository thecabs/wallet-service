<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identifiant externe = sub Keycloak (proprio du wallet)
            $table->uuid('external_id');

            // Scoping d’agence (ABAC) – vient du JWT (claim agency_id)
            $table->string('agency_id', 64)->nullable();

            // Montant et devise
            $table->decimal('solde', 15, 2)->default(0);
            $table->char('devise', 3)->default('XAF');

            // Statut métier (aligné avec tes actions activate/suspend/close)
            $table->enum('statut', ['actif', 'inactif', 'suspendu'])->default('actif');

            $table->timestamps();

            // Index utiles
            $table->index('external_id');
            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
