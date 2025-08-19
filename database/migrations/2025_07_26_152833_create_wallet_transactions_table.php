<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');

            $table->enum('type', ['debit', 'credit']);
            $table->decimal('montant', 15, 2);
            $table->char('devise', 3);
            $table->string('description', 255)->nullable();

            // idempotence / reconciliation
            $table->string('reference', 255)->unique();

            $table->enum('statut', ['pending', 'completed', 'failed', 'cancelled'])->default('pending');

            $table->timestamps();

            // FK + index (utile pour les listings par wallet)
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            $table->index('wallet_id');
            $table->index(['reference', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
