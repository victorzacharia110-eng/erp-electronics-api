<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SupplierMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_active) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($user->role !== 'supplier' && !$user->isSuperadmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
