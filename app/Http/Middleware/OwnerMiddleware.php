<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OwnerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isOwner()) {
            return response()->json(['message' => 'Unauthorized. Owner access required.'], 403);
        }

        return $next($request);
    }
}
