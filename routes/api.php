<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\BalanceController;

/**
 * Health (public)
 */
Route::get('/health', [WalletController::class, 'health'])->name('health.check');

/**
 * Health DB (public) — à restreindre en prod (IP allowlist / env)
 */
Route::get('/health/database', function () {
    try {
        DB::connection()->getPdo();
        return response()->json([
            'database'   => 'OK',
            'connection' => config('database.default'),
            'timestamp'  => now()->toIso8601String(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'database'  => 'ERROR',
            'message'   => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ], 500);
    }
})->name('health.database');

/**
 * Périmètre protégé : JWT + ABAC (contexte + tag FINANCIAL + PDP)
 */
Route::middleware(['keycloak', 'context.enricher', 'resource.tag:FINANCIAL', 'pdp'])->group(function () {

    // ---- Lecture (AJOUTS) --------------------------------------------------

    // Liste des wallets du sujet (admin voit tout, directeur d'agence voit son agence)
    Route::get('/wallets', [WalletController::class, 'index'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin,directeur_agence', 'throttle:wallet-read'])
        ->name('wallets.index');

    // Détail d’un wallet
    Route::get('/wallets/{wallet}', [WalletController::class, 'show'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin,directeur_agence', 'throttle:wallet-read'])
        ->whereUuid('wallet')
        ->name('wallets.show');

    // Historique des transactions (alias pratique)
    Route::get('/wallets/{wallet}/transactions', [BalanceController::class, 'getStatement'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin,directeur_agence', 'throttle:wallet-read'])
        ->whereUuid('wallet')
        ->name('wallets.transactions');

    // ---- Création / statut --------------------------------------------------

    // Création portefeuille — écriture ⇒ throttle + idempotency
    Route::post('/wallets', [WalletController::class, 'create'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin', 'throttle:wallet-write', 'idempotency:600'])
        ->name('wallets.create');

    // Changement de statut (POST, conforme à ton existant)
    Route::post('/wallets/{wallet}/close',   [WalletController::class, 'close'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin'])
        ->whereUuid('wallet')
        ->name('wallets.close');

    Route::post('/wallets/{wallet}/suspend', [WalletController::class, 'suspend'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin'])
        ->whereUuid('wallet')
        ->name('wallets.suspend');

    Route::post('/wallets/{wallet}/activate', [WalletController::class, 'activate'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin'])
        ->whereUuid('wallet')
        ->name('wallets.activate');

    // ---- Transactions -------------------------------------------------------

    Route::post('/wallets/{wallet}/credit', [TransactionController::class, 'credit'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin', 'throttle:wallet-tx', 'idempotency:600'])
        ->whereUuid('wallet')
        ->name('wallets.credit');

    Route::post('/wallets/{wallet}/debit',  [TransactionController::class, 'debit'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin', 'throttle:wallet-tx', 'idempotency:600'])
        ->whereUuid('wallet')
        ->name('wallets.debit');

    // Solde / relevé (compat)
    Route::get('/wallets/{wallet}/balance',   [BalanceController::class, 'getBalance'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin,directeur_agence'])
        ->whereUuid('wallet')
        ->name('wallets.balance');

    Route::get('/wallets/{wallet}/statement', [BalanceController::class, 'getStatement'])
        ->middleware(['check.role:client_bancaire,client_non_bancaire,admin,directeur_agence'])
        ->whereUuid('wallet')
        ->name('wallets.statement');
});
