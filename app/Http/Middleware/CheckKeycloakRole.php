<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckKeycloakRole
{
    public function handle(Request $request, Closure $next, $requiredRole)
    {
        $tokenData = $request->attributes->get('token_data');

        if (!$tokenData) {
            return response()->json(['error' => 'Access Denied - Token data missing'], 403);
        }

        // ✅ Correction : Accès correct aux rôles
        $userRoles = $tokenData->realm_access->roles ?? [];

        // Gestion des multiples rôles (admin,client,etc)
        $requiredRoles = explode(',', $requiredRole);

        foreach ($requiredRoles as $role) {
            if (in_array($role, $userRoles)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => 'Access Denied - Missing role',
            'required_roles' => $requiredRoles,
            'user_roles' => $userRoles
        ], 403);
    }
}