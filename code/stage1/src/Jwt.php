<?php
declare(strict_types=1);

namespace InfraLogging;

/**
 * Minimal HS256 JSON Web Token encode/decode, written by hand for Stage 1
 * (Stage 2 uses a library). Only HS256 is accepted, so a token cannot
 * switch to "alg": "none" or another algorithm.
 */
final class Jwt
{
    /** @param array<string,mixed> $claims */
    public static function encode(array $claims, string $secret): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = self::b64(hash_hmac('sha256', "$header.$payload", $secret, true));
        return "$header.$payload.$signature";
    }

    /**
     * Verifies the signature and expiry and returns the claims.
     * Any problem is a 401.
     *
     * @return array<string,mixed>
     */
    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw ApiException::unauthorized('Token is malformed.');
        }
        [$header64, $payload64, $signature64] = $parts;

        $header = self::decodeJson($header64);
        if (($header['alg'] ?? null) !== 'HS256') {
            throw ApiException::unauthorized('Token algorithm is not supported.');
        }

        $expected = hash_hmac('sha256', "$header64.$payload64", $secret, true);
        $signature = self::unb64($signature64);
        if ($signature === null || !hash_equals($expected, $signature)) {
            throw ApiException::unauthorized('Token signature is invalid.');
        }

        $claims = self::decodeJson($payload64);
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= time()) {
            throw ApiException::unauthorized('Token has expired.');
        }
        if (!is_string($claims['sub'] ?? null) || !is_string($claims['scope'] ?? null)) {
            throw ApiException::unauthorized('Token is missing required claims.');
        }
        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /** @return array<string,mixed> */
    private static function decodeJson(string $part): array
    {
        $json = self::unb64($part);
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data)) {
            throw ApiException::unauthorized('Token is malformed.');
        }
        return $data;
    }
}
