<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $validKey = env('API_KEY');
        $apiKey = $request->header('X-API-Key');

        if (!$validKey) {
            // Jika tidak diset, izinkan saja (seperti versi python)
            return $next($request);
        }

        if ($apiKey !== $validKey) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'API key tidak valid atau tidak ada. Sertakan header: X-API-Key: <key>'
            ], 401);
        }

        return $next($request);
    }
}
