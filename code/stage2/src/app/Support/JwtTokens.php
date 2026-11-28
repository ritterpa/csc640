<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Issues and checks the same HS256 bearer tokens as Stage 1 (claims sub,
 * scope, iat, exp), using the firebase/php-jwt library in place of Stage 1's
 * hand-written src/Jwt.php. Keeping the token contract unchanged lets the
 * same 73 acceptance tests run against both stages.
 */
final class JwtTokens
{
    public static function ttl(): int
    {
        return (int) config('jwt.ttl', 3600);
    }

    public static function issue(string $username, string $scope): string
    {
        $now = CarbonImmutable::now()->getTimestamp();
        return JWT::encode([
            'sub'   => $username,
            'scope' => $scope,
            'iat'   => $now,
            'exp'   => $now + self::ttl(),
        ], self::secret(), 'HS256');
    }

    /**
     * Used by the "jwt" auth guard (AppServiceProvider). Returns the token's
     * user, or null for a missing or invalid token, which Laravel turns into
     * a 401. Stateless like Stage 1: no database lookup per request.
     */
    public static function userFromRequest(Request $request): ?GenericUser
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return null;
        }
        $secret = self::secret(); // outside the try, so a missing secret is a 500, not a 401
        try {
            // The Key pins HS256, so "alg": "none" or any other algorithm is rejected.
            // decode() also rejects expired tokens (exp in the past).
            $claims = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($claims->sub ?? null) || ! is_string($claims->scope ?? null) || ! is_int($claims->exp ?? null)) {
            return null; // Stage 1 requires these claims too
        }
        return new GenericUser(['id' => $claims->sub, 'name' => $claims->sub, 'scope' => $claims->scope]);
    }

    private static function secret(): string
    {
        $secret = (string) config('jwt.secret', '');
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET is not set in .env.');
        }
        return $secret;
    }
}
