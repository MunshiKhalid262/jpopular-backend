<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/**
 * The single place the API response envelope is defined.
 *
 * See ARCHITECTURE-V1.md section 9.1:
 *   success: { "success": true, "data": {...}, "meta": {...} }
 *   error:   { "success": false, "message": "...", "errors": {...}, "code": "..." }
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        $payload = [
            'success' => true,
            'data' => self::normalizeData($data),
        ];

        $paginationMeta = self::paginationMeta($data);

        if ($paginationMeta !== []) {
            $meta = ['pagination' => $paginationMeta] + $meta;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function error(
        string $message,
        int $status = 400,
        array $errors = [],
        ?string $code = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => null], 200);
    }

    /**
     * Unwrap resources/paginators so `data` is never double-nested.
     */
    private static function normalizeData(mixed $data): mixed
    {
        if ($data === null) {
            return null;
        }

        if ($data instanceof ResourceCollection) {
            return $data->resolve();
        }

        if ($data instanceof JsonResource) {
            return $data->resolve();
        }

        if ($data instanceof AbstractPaginator) {
            return $data->items();
        }

        return $data;
    }

    /**
     * @return array<string, int|null>
     */
    private static function paginationMeta(mixed $data): array
    {
        $paginator = match (true) {
            $data instanceof AbstractPaginator => $data,
            $data instanceof ResourceCollection && $data->resource instanceof AbstractPaginator => $data->resource,
            default => null,
        };

        if ($paginator === null) {
            return [];
        }

        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
            'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null,
        ];
    }
}
