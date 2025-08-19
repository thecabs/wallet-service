<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateWalletRequest;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Health (appelée par /health)
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'service'   => 'Wallet Service',
            'status'    => 'OK',
            'timestamp' => now()->toIso8601String(),
            'version'   => config('app.version', '1.0.0'),
        ]);
    }

    /**
     * Création de portefeuille (sans régression).
     * - Tolère "devise" ou "currency"
     * - Idempotency-Key: supporte X-Idempotency-Key (ancien) et Idempotency-Key (nouveau)
     * - Scoping d’agence (agency_id) si fourni par le contexte
     */
    public function create(CreateWalletRequest $request): JsonResponse
    {
        try {
            $externalId = $this->getExternalId($request);
            if (!$externalId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $agencyId = $this->getAgencyId($request);

            // devise/currency : on tolère les deux noms de champs
            $devise = $request->string('devise')->toString()
                   ?: $request->string('currency')->toString()
                   ?: 'XAF';

            // Idempotency: compat X-Idempotency-Key (legacy) et Idempotency-Key (nouveau)
            $idempotencyKey = $request->header('X-Idempotency-Key') ?: $request->header('Idempotency-Key');

            // --- Création via service (signature existante; args nommés tolérés par PHP 8) ---
            $wallet = $this->walletService->createWallet(
                $externalId,
                $request->attributes->get('local_user_id'), // optionnel
                $devise,
                agencyId: $agencyId,                        // si non géré par le service, sera ignoré
                idempotencyKey: $idempotencyKey             // idem
            );

            // --- Assure le scoping d’agence si le service ne l’a pas posé ---
            if ($agencyId && empty($wallet->agency_id)) {
                $wallet->agency_id = $agencyId;
                $wallet->save();
            }

            Log::channel('audit')->info('wallet.created', [
                'wallet_id'   => $wallet->id,
                'external_id' => $wallet->external_id,
                'agency_id'   => $wallet->agency_id,
                'currency'    => $wallet->devise ?? $wallet->currency ?? $devise,
                'actor'       => $request->attributes->get('preferred_username') ?? $externalId,
                'via'         => 'api',
                'req_id'      => $request->header('X-Request-Id'),
            ]);

            return response()->json([
                'message' => 'Portefeuille créé avec succès',
                'wallet'  => $this->sanitizeWallet($wallet),
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation error', 'details' => $e->errors()], 422);
        } catch (QueryException $e) {
            // ex: contrainte unique (wallet déjà existant)
            if ((string) $e->getCode() === '23000') {
                return response()->json(['error' => 'Wallet already exists'], 409);
            }
            Log::error('wallet.create.query_exception', ['code' => $e->getCode(), 'msg' => $e->getMessage()]);
            return response()->json(['error' => 'Database error'], 500);
        } catch (\Throwable $e) {
            Log::error('wallet.create.unexpected', ['msg' => $e->getMessage()]);
            return response()->json(['error' => 'Erreur lors de la création du portefeuille'], 500);
        }
    }

    /**
     * Fermer un portefeuille (→ statut inactif)
     */
    public function close(Wallet $wallet): JsonResponse
    {
        try {
            $this->authorizeWalletAction($wallet);
            $this->walletService->closeWallet($wallet);

            Log::channel('audit')->info('wallet.closed', [
                'wallet_id'   => $wallet->id,
                'external_id' => $wallet->external_id,
                'actor'       => request()->attributes->get('preferred_username') ?? $this->getExternalId(request()),
                'req_id'      => request()->header('X-Request-Id'),
            ]);

            return response()->json(['message' => 'Portefeuille fermé']);
        } catch (\Throwable $e) {
            Log::warning('wallet.close.failed', ['wallet_id' => $wallet->id, 'msg' => $e->getMessage()]);
            return response()->json(['error' => 'Impossible de fermer le portefeuille'], 500);
        }
    }

    /**
     * Suspendre un portefeuille (→ statut suspendu)
     */
    public function suspend(Wallet $wallet): JsonResponse
    {
        try {
            $this->authorizeWalletAction($wallet);
            $this->walletService->suspendWallet($wallet);

            Log::channel('audit')->info('wallet.suspended', [
                'wallet_id'   => $wallet->id,
                'external_id' => $wallet->external_id,
                'actor'       => request()->attributes->get('preferred_username') ?? $this->getExternalId(request()),
                'req_id'      => request()->header('X-Request-Id'),
            ]);

            return response()->json(['message' => 'Portefeuille suspendu']);
        } catch (\Throwable $e) {
            Log::warning('wallet.suspend.failed', ['wallet_id' => $wallet->id, 'msg' => $e->getMessage()]);
            return response()->json(['error' => 'Impossible de suspendre le portefeuille'], 500);
        }
    }

    /**
     * Réactiver un portefeuille (→ statut actif)
     */
    public function activate(Wallet $wallet): JsonResponse
    {
        try {
            $this->authorizeWalletAction($wallet);
            $this->walletService->activateWallet($wallet);

            Log::channel('audit')->info('wallet.activated', [
                'wallet_id'   => $wallet->id,
                'external_id' => $wallet->external_id,
                'actor'       => request()->attributes->get('preferred_username') ?? $this->getExternalId(request()),
                'req_id'      => request()->header('X-Request-Id'),
            ]);

            return response()->json(['message' => 'Portefeuille activé']);
        } catch (\Throwable $e) {
            Log::warning('wallet.activate.failed', ['wallet_id' => $wallet->id, 'msg' => $e->getMessage()]);
            return response()->json(['error' => 'Impossible d’activer le portefeuille'], 500);
        }
    }

    // ===================== Helpers =====================

    private function getExternalId(Request $request): ?string
    {
        return $request->attributes->get('external_id')
            ?? $request->attributes->get('sub')
            ?? data_get($request->attributes->get('zt.subject'), 'sub')
            ?? data_get($request->attributes->get('token_data'), 'sub')
            ?? $request->input('user_id'); // ultime fallback
    }

    private function getAgencyId(Request $request): ?string
    {
        return $request->attributes->get('agency_id')
            ?? data_get($request->attributes->get('zt.subject'), 'agency_id')
            ?? data_get($request->attributes->get('token_data'), 'agency_id');
    }

    private function getRoles(Request $request): array
    {
        $roles = $request->attributes->get('token_roles')
            ?? data_get($request->attributes->get('zt.subject'), 'roles')
            ?? data_get($request->attributes->get('token_data'), 'realm_access.roles', []);
        return array_map('strtolower', (array) $roles);
    }

    /**
     * Autorisations locales (owner/admin/directeur agence même agence)
     */
    protected function authorizeWalletAction(Wallet $wallet): void
    {
        $req        = request();
        $externalId = $this->getExternalId($req);
        $agencyId   = $this->getAgencyId($req);
        $roles      = $this->getRoles($req);

        $isOwner = $externalId && $wallet->external_id === $externalId;

        $isAdmin = (bool) array_intersect($roles, [
            'admin','superadmin','bo_admin','bo_superadmin','role_admin','role_superadmin',
        ]);

        $isAgencyDirectorSameAgency =
            (bool) array_intersect($roles, [
                'directeur_agence','agency_director','role_agency_director',
            ])
            && $agencyId
            && $wallet->agency_id
            && $wallet->agency_id === $agencyId;

        if (!($isOwner || $isAdmin || $isAgencyDirectorSameAgency)) {
            Log::warning('wallet.action.forbidden', [
                'wallet_id'     => $wallet->id,
                'wallet_agency' => $wallet->agency_id,
                'caller_sub'    => $externalId,
                'caller_agency' => $agencyId,
                'caller_roles'  => $roles,
                'req_id'        => $req->header('X-Request-Id'),
            ]);
            abort(403, 'Forbidden');
        }
    }

    private function sanitizeWallet(Wallet $w): array
    {
        return [
            'id'          => $w->id,
            'external_id' => $w->external_id,
            'agency_id'   => $w->agency_id,
            'devise'      => $w->devise ?? $w->currency ?? null,
            'solde'       => $w->solde ?? $w->balance ?? null,
            'statut'      => $w->statut ?? $w->status ?? null,
            'created_at'  => $w->created_at?->toIso8601String(),
            'updated_at'  => $w->updated_at?->toIso8601String(),
        ];
    }
}
