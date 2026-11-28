<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Request input checks. Uses Laravel's validator for simple rules and turns
 * every failure into a 400 BAD_REQUEST (Laravel's default would be 422,
 * which does not match the Stage 1 contract).
 */
final class ApiInput
{
    /** The largest value of a MySQL INT column: the upper limit for ids and page numbers. */
    public const INT_MAX = 2147483647;

    /**
     * Validates $data with Laravel rules; the first failure becomes a 400.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $rules
     * @return array<string,mixed> the validated values
     */
    public static function validate(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw ApiException::badRequest($validator->errors()->first());
        }
        return $validator->validated();
    }

    /**
     * Decodes the raw request body as a JSON object. Laravel silently treats
     * invalid JSON as an empty body, so the body is parsed here to reject it.
     *
     * @return array<string,mixed>
     */
    public static function jsonObject(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::badRequest('Request body must be valid JSON.');
        }
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiException::badRequest('Request body must be a JSON object.');
        }
        return $data;
    }

    public static function guid(mixed $value, string $name): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ApiException::badRequest("$name must be a GUID.");
        }
        return strtolower($value);
    }

    public static function positiveInt(mixed $value, string $name): int
    {
        $valid = self::validate([$name => $value], [$name => 'required|integer|min:1|max:' . self::INT_MAX]);
        return (int) $valid[$name];
    }

    /**
     * ?page= and ?limit= (defaults 1 and $defaultLimit; limit at most 100).
     *
     * @param array<string,mixed> $query
     * @return array{page:int, limit:int}
     */
    public static function paging(array $query, int $defaultLimit = 50): array
    {
        $valid = self::validate($query, [
            'page'  => 'sometimes|integer|min:1|max:' . self::INT_MAX,
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        return [
            'page'  => (int) ($valid['page'] ?? 1),
            'limit' => (int) ($valid['limit'] ?? $defaultLimit),
        ];
    }
}
