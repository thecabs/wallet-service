<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreditWalletRequest;
use App\Http\Requests\DebitWalletRequest;
use App\Models\Wallet;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TransactionController extends Controller
{
    public function __construct(private TransactionService $transactionService) {}

    private function getExternalId(Request $request): ?string
    {
        return $request->attributes->get('external_id')
            ?? $request->attributes->get('sub')
            ?? data_get($request->attributes->get('zt.subject'), 'sub')
            ?? data_get($request->attributes->get('token_data'), 'sub');
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
            ?? data_get($request->attributes->get('token_data'), 'realm_access.roles');
        return array_map('strtolower', (array) $roles);
    }

    private function ensureIdempotency(Request $request, Wallet $wallet): void
    {
        $idem = $request->header('Idempotency-Key');
        abort_if(!$idem, 400, 'Idempotency-Key required');

        $key = "idem:tx:{$wallet->id}:{$idem}";
        if (cache()->has($key)) {
            abort(409, 'Duplicate operation');
        }
        cache()->put($key, 1, now()->addMinutes(10));
    }

    private function authorizeTransactionAction(Request $request, Wallet $wallet): void
    {
        $externalId = $this->getExternalId($request);
        abort_if(!$externalId, 401, 'Unauthorized');

        $roles    = $this->getRoles($request);
        $agencyId = $this->getAgencyId($request);

        $isOwner = $wallet->external_id === $externalId;

        $isAdmin = (bool) array_intersect($roles, [
            'admin','superadmin','bo_admin','bo_superadmin','role_admin','role_superadmin',
        ]);

        $isAgencyDirectorSameAgency =
            (bool) array_intersect($roles, ['directeur_agence','agency_director','role_agency_director'])
            && $agencyId && $wallet->agency_id && $wallet->agency_id === $agencyId;

        if (!($isOwner || $isAdmin || $isAgencyDirectorSameAgency)) {
            logger()->warning('wallet.tx.forbidden', [
                'wallet_id'     => $wallet->id,
                'wallet_agency' => $wallet->agency_id,
                'caller_sub'    => $externalId,
                'caller_agency' => $agencyId,
                'caller_roles'  => $roles,
            ]);
            abort(403, 'Forbidden');
        }
    }

    public function credit(CreditWalletRequest $request, Wallet $wallet): JsonResponse
    {
        $this->authorizeTransactionAction($request, $wallet);
        $this->ensureIdempotency($request, $wallet);

        try {
            $transaction = $this->transactionService->creditWallet(
                $wallet,
                (float) $request->input('amount'),
                (string) $request->input('reference'),
                (string) ($request->input('description') ?? ''),
                (string) ($request->input('period') ?? 'daily') // si tu veux faire respecter un plafond de crédit
            );

            return response()->json([
                'message'     => 'Crédit effectué',
                'transaction' => $transaction,
            ], 201);
        } catch (Throwable $e) {
            // Règles métier → 422 ; reste → 500
            $code = str_contains($e->getMessage(), 'plafond') ||
                    str_contains($e->getMessage(), 'Portefeuille') ||
                    str_contains($e->getMessage(), 'Solde')
                ? 422 : 500;

            return response()->json([
                'error'   => 'Credit failed',
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    public function debit(DebitWalletRequest $request, Wallet $wallet): JsonResponse
    {
        $this->authorizeTransactionAction($request, $wallet);
        $this->ensureIdempotency($request, $wallet);

        try {
            $transaction = $this->transactionService->debitWallet(
                $wallet,
                (float) $request->input('amount'),
                (string) $request->input('reference'),
                (string) ($request->input('description') ?? ''),
                (string) ($request->input('period') ?? 'daily')
            );

            return response()->json([
                'message'     => 'Débit effectué',
                'transaction' => $transaction,
            ], 201);
        } catch (Throwable $e) {
            $code = str_contains($e->getMessage(), 'plafond') ||
                    str_contains($e->getMessage(), 'Portefeuille') ||
                    str_contains($e->getMessage(), 'Solde')
                ? 422 : 500;

            return response()->json([
                'error'   => 'Debit failed',
                'message' => $e->getMessage(),
            ], $code);
        }
    }
}
