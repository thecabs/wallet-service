<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WalletQueryController extends Controller
{
    // GET /api/wallets?owner=&status=&statut=&currency=&devise=&search=&limit=&page=
    public function index(Request $request): JsonResponse
    {
        // Valide à la fois les noms FR et EN
        $v = Validator::make($request->all(), [
            'page'     => 'sometimes|integer|min:1',
            'limit'    => 'sometimes|integer|min:1|max:100',
            'status'   => 'sometimes|string|in:actif,inactif,suspendu',
            'statut'   => 'sometimes|string|in:actif,inactif,suspendu',
            'currency' => 'sometimes|string|size:3',
            'devise'   => 'sometimes|string|size:3',
            'search'   => 'sometimes|string|min:1',
            'owner'    => 'sometimes|string',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $q = Wallet::query();

        // owner -> external_id
        if ($request->filled('owner')) {
            $q->where('external_id', $request->string('owner')->toString());
        }

        // status/statut -> colonne 'statut'
        $statut = $request->string('statut')->toString()
            ?: $request->string('status')->toString();
        if (!empty($statut)) {
            $q->where('statut', $statut);
        }

        // currency/devise -> colonne 'devise'
        $devise = $request->string('devise')->toString()
            ?: $request->string('currency')->toString();
        if (!empty($devise)) {
            $q->where('devise', strtoupper($devise));
        }

        // search sur id ou external_id (pas de 'label' en DB)
        if ($request->filled('search')) {
            $s = $request->string('search')->toString();
            $q->where(function (Builder $qq) use ($s) {
                $qq->where('id', 'like', "%{$s}%")
                   ->orWhere('external_id', 'like', "%{$s}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->integer('limit', 20)));
        $data = $q->orderByDesc('created_at')->paginate($perPage);

        logger()->info('wallet.query.index', [
            'filters' => [
                'owner'   => $request->input('owner'),
                'statut'  => $statut,
                'devise'  => $devise,
                'search'  => $request->input('search'),
            ],
            'page'    => $data->currentPage(),
            'limit'   => $perPage,
        ]);

        return response()->json($data);
    }

    // GET /api/wallets/{wallet}
    public function show(Wallet $wallet): JsonResponse
    {
        // Prépare l’ABAC (étape 1)
        request()->attributes->set('owner_agency', $wallet->agency_id ?? null);
        request()->attributes->set('sensitivity', 'FINANCIAL');

        return response()->json($wallet);
    }
}
