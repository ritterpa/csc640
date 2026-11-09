<?php
declare(strict_types=1);

namespace InfraLogging\Controllers;

use InfraLogging\ApiException;
use InfraLogging\Request;
use InfraLogging\Response;
use InfraLogging\Validator;

/**
 * POST /api/v1/auth/login (public)
 * Exchanges a username and password for a bearer token.
 */
final class AuthController extends Controller
{
    /**
     * A bcrypt hash of a random throwaway password. When the username does
     * not exist, the password is still checked against this hash so the
     * response takes the same time as a wrong password and does not reveal
     * which usernames exist.
     */
    private const DUMMY_HASH = '$2y$10$ZrCYe7a/ZvVXth4C9qEsHOEl4bcfV8wxR18BOesw/UZWZn90pYhJK';

    /** @param array<string,string> $params */
    public function login(Request $request, array $params): Response
    {
        $body = $request->json();
        $username = Validator::requiredString($body, 'username', 100);
        $password = $body['password'] ?? null;
        if (!is_string($password) || $password === '') {
            throw ApiException::badRequest('password is required.');
        }

        $user = $this->query(
            'SELECT UserName, PasswordHash, Scope FROM ApiUser WHERE UserName = ? AND IsActive = 1',
            [$username]
        )->fetch();

        $hash = $user === false ? self::DUMMY_HASH : $user['PasswordHash'];
        $passwordOk = password_verify($password, $hash);
        if ($user === false || !$passwordOk) {
            throw ApiException::unauthorized('Invalid username or password.');
        }

        $auth = $this->app->auth();
        return Response::json(200, ['data' => [
            'token'     => $auth->issueToken($user['UserName'], $user['Scope']),
            'tokenType' => 'Bearer',
            'expiresIn' => $auth->ttlSeconds,
            'scope'     => $user['Scope'],
        ]]);
    }
}
