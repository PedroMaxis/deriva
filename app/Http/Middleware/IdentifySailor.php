<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Sem cadastro: um cookie cifrado é o marujo. No banco fica só o hash. */
class IdentifySailor
{
    public const COOKIE = 'marujo';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || ! Str::isUuid($token)) {
            $token = (string) Str::uuid();
        }
        Cookie::queue(self::COOKIE, $token, 60 * 24 * 365 * 2);
        $request->attributes->set('sailor', self::key($token));

        return $next($request);
    }

    public static function key(string $token): string
    {
        return hash_hmac('sha256', $token, config('app.key'));
    }
}
