<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * CLAUDE.md Section 10: every API response uses the {data, meta, errors}
 * envelope. `meta` is included only when provided; `errors` is included
 * only on non-2xx responses.
 */
trait ApiResponds
{
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function respondWithData(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * $data carries anything the client needs to recover from the error
     * (e.g. the existing report a duplicate draft should open instead).
     *
     * @param  array<int, string>  $errors
     */
    protected function respondWithErrors(array $errors, int $status = 422, mixed $data = null): JsonResponse
    {
        return response()->json(['data' => $data, 'errors' => $errors], $status);
    }
}
