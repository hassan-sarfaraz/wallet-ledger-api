<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('wallet.api_token');
        $header = $request->header('Authorization', '');

        if ($expected === '' || ! preg_match('/\ABearer ([^\s]+)\z/i', $header, $matches)
            || ! hash_equals(hash('sha256', $expected, true), hash('sha256', $matches[1], true))) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'A valid bearer token is required.',
            ], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
