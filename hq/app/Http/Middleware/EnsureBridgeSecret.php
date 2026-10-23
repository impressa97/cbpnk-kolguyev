<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shared secret for the Reforger mod bridge. */
class EnsureBridgeSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('reforger.bridge_secret', '');
        $provided = (string) $request->header('X-Reforger-Bridge-Secret', '');

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
