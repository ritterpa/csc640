<?php
declare(strict_types=1);

namespace InfraLogging;

/**
 * Issues tokens at login and checks the bearer token and scope on every
 * protected endpoint. Order of checks: missing/invalid token (401), then
 * missing scope (403). Controllers call this before validating input.
 */
final class Auth
{
    public function __construct(
        private readonly string $secret,
        public readonly int $ttlSeconds
    ) {}

    public function issueToken(string $username, string $scope): string
    {
        $now = time();
        return Jwt::encode([
            'sub'   => $username,
            'scope' => $scope,
            'iat'   => $now,
            'exp'   => $now + $this->ttlSeconds,
        ], $this->secret);
    }

    /**
     * @param string $scope 'read' or 'write'
     * @return array<string,mixed> the token claims
     */
    public function requireScope(Request $request, string $scope): array
    {
        $header = $request->header('Authorization');
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            throw ApiException::unauthorized('A bearer token is required.');
        }

        $claims = Jwt::decode($m[1], $this->secret);

        $granted = preg_split('/\s+/', trim($claims['scope']));
        if (!in_array($scope, $granted, true)) {
            throw ApiException::forbidden("This endpoint requires the '$scope' scope.");
        }
        return $claims;
    }
}
