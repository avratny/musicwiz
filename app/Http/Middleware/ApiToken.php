<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ist API_TOKEN gesetzt, nur mit Token (Header „Authorization: Bearer …“ oder „X-Api-Token: …“) – sonst offen */
class ApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('musicwiz.api_token');
        if ($token === '') {
            return $next($request);
        }
        $given = (string) ($request->bearerToken() ?: $request->header('X-Api-Token'));
        if (! hash_equals($token, $given)) {
            return response()->json(['error' => 'Token fehlt oder falsch.'], 401);
        }

        return $next($request);
    }
}
