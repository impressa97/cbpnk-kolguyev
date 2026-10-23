<?php

namespace App\Http\Middleware;

use App\Support\LocalCommander;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Sign in the local commander when the request has no one else. */
class UseLocalCommander
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            Auth::login(LocalCommander::resolve());
        }

        return $next($request);
    }
}
