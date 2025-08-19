<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class BalanceController extends Controller
{
    public function __construct(private TransactionService $transactionService) {}

    // ---- Helpers ------------------------------------------------------------

    private function externalId(Request $request): ?string
    {
        return $request->attributes->get('external_id')
            ?? $request->attributes->get('sub')
            ?? data_get($request->attributes->get('zt.subject'), 'sub')
            ?? data_get($request->attributes->get('token_data'), 'sub');
    }

    /** Rôles (minuscule) extraits du JWT */
    private function roles(Request $request): array
    {
        $roles = $request->attributes->get('token_roles')
            ?? data_get($request->attributes->get('zt.subject'), 'roles')
            ?? data_get($request->attributes->get('token_data'), 'realm_access.roles');
        return collect((array)$roles)
            ->filter()
            ->map(fn ($r) => strtolower((string) $r))
            ->values()
            ->all();
    }

    /** agency_id depuis le JWT si mappé dans Keycloak */
    private function agencyId(Request $request): ?string
    {
        return $request->attributes->get('agency_id')
            ?? data_get($request->attributes->get('zt.subject'), 'agency_id')
            ?? data_get($request->attributes->get('token_data'), 'agency_id');
    }

    /** Autorise la LECTURE: propriétaire OU admin OU directeur même agence */
    private function authorizeWalletRead(Request $request, Wallet $wallet): void
    {
        $externalId = $this->externalId($request);
        if (!$externalId) {
            abort(401, 'Unauthorized');
        }

        $roles = $this->roles($request);

        $isOwner = $wallet->external_id === $externalId;

        $isAdmin = (bool) array_intersect($roles, [
            'admin','superadmin','bo_admin','bo_superadmin','role_admin','role_superadmin'
        ]);

        $userAgency   = $this->agencyId($request);
        $walletAgency = $wallet->agency_id ?? null;
        $isAgencyDirectorSameAgency =
            (bool) array_intersect($roles, ['directeur_agence','agency_director','role_agency_director'])
            && $userAgency && $walletAgency && $userAgency === $walletAgency;

        if (!($isOwner || $isAdmin || $isAgencyDirectorSameAgency)) {
            abort(403, 'Forbidden: You do not own this wallet');
        }
    }

    // ---- Endpoints ----------------------------------------------------------

    /** Obtenir le solde actuel du portefeuille */
    public function getBalance(Request $request, Wallet $wallet): JsonResponse
    {
        $this->authorizeWalletRead($request, $wallet);

        return response()->json([
            'wallet_id'    => $wallet->id,
            'balance'      => (float) $wallet->solde,
            'currency'     => $wallet->devise,
            'status'       => $wallet->statut,
            'last_updated' => $wallet->updated_at?->toIso8601String(),
        ]);
    }

    /** Relevé / transactions */
    public function getStatement(Request $request, Wallet $wallet): JsonResponse
    {
        $this->authorizeWalletRead($request, $wallet);

        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            // ⚠️ en DB: type ∈ {credit,debit} -> on retire 'transfer'
            'type'       => 'nullable|in:credit,debit',
            'limit'      => 'nullable|integer|min:1|max:100',
            'page'       => 'nullable|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid parameters', 'details' => $validator->errors()], 400);
        }

        $transactions = $this->transactionService->getStatement(
            $wallet,
            $request->input('start_date'),
            $request->input('end_date'),
            $request->input('type'),
            $request->input('limit', 25),
            $request->input('page', 1)
        );

        return response()->json([
            'wallet_id'    => $wallet->id,
            'balance'      => (float) $wallet->solde,
            'currency'     => $wallet->devise,
            'transactions' => $transactions->items(),
            'pagination'   => [
                'current_page' => $transactions->currentPage(),
                'per_page'     => $transactions->perPage(),
                'total'        => $transactions->total(),
                'total_pages'  => $transactions->lastPage(),
            ],
        ]);
    }
}
