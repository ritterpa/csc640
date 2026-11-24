<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\ApiUser;
use App\Support\ApiInput;
use App\Support\JwtTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * POST /api/v1/auth/login (public): exchanges a username and password for a
 * bearer token, with the same request and response as Stage 1.
 */
class AuthController extends Controller
{
    /**
     * A bcrypt hash of a random throwaway password. For an unknown username
     * the password is still checked against it, so the response takes the
     * same time as a wrong password and does not reveal which usernames exist.
     */
    private const DUMMY_HASH = '$2y$10$ZrCYe7a/ZvVXth4C9qEsHOEl4bcfV8wxR18BOesw/UZWZn90pYhJK';

    public function login(Request $request): JsonResponse
    {
        $input = ApiInput::validate(ApiInput::jsonObject($request), [
            'username' => 'required|string|max:100',
            'password' => 'required|string',
        ]);

        $user = ApiUser::query()
            ->where('UserName', $input['username'])
            ->where('IsActive', true)
            ->first();

        // Hash::check accepts the $2y$ bcrypt hashes created by setup-database.sh.
        $passwordOk = Hash::check($input['password'], $user?->PasswordHash ?? self::DUMMY_HASH);
        if ($user === null || ! $passwordOk) {
            throw ApiException::unauthorized('Invalid username or password.');
        }

        return response()->json(['data' => [
            'token'     => JwtTokens::issue($user->UserName, $user->Scope),
            'tokenType' => 'Bearer',
            'expiresIn' => JwtTokens::ttl(),
            'scope'     => $user->Scope,
        ]]);
    }
}
