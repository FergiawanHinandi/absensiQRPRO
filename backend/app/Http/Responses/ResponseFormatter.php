<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * ResponseFormatter
 *
 * Standardized API response formatter for consistent responses across mobile and web.
 *
 * STANDARD FORMAT:
 * {
 *   "success": true|false,
 *   "code": 200,
 *   "message": "Success message",
 *   "data": {...},
 *   "meta": {...}
 * }
 *
 * USAGE:
 * - ResponseFormatter::success($data, $message)
 * - ResponseFormatter::error($message, $code, $errors)
 * - ResponseFormatter::paginated($paginator, $message)
 *
 * @version 1.0.0
 */
class ResponseFormatter
{
    /**
     * Success response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $code HTTP status code
     * @return JsonResponse
     */
    public static function success(
        $data = null,
        string $message = 'Success',
        int $code = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Error response
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param mixed $errors Validation errors or additional error details
     * @param mixed $data Additional data (optional)
     * @return JsonResponse
     */
    public static function error(
        string $message = 'Error',
        int $code = 400,
        $errors = null,
        $data = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'code' => $code,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Paginated response
     *
     * @param LengthAwarePaginator $paginator Laravel paginator
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'Success'
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'code' => 200,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ], 200);
    }

    /**
     * Collection response (non-paginated list)
     *
     * @param Collection|array $collection Data collection
     * @param string $message Success message
     * @param array $meta Additional metadata
     * @return JsonResponse
     */
    public static function collection(
        $collection,
        string $message = 'Success',
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'code' => 200,
            'message' => $message,
            'data' => $collection instanceof Collection ? $collection->values()->all() : $collection,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, 200);
    }

    /**
     * Created response (201)
     *
     * @param mixed $data Created resource data
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function created(
        $data = null,
        string $message = 'Resource created successfully'
    ): JsonResponse {
        return self::success($data, $message, 201);
    }

    /**
     * Updated response (200)
     *
     * @param mixed $data Updated resource data
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function updated(
        $data = null,
        string $message = 'Resource updated successfully'
    ): JsonResponse {
        return self::success($data, $message, 200);
    }

    /**
     * Deleted response (200)
     *
     * @param string $message Success message
     * @return JsonResponse
     */
    public static function deleted(
        string $message = 'Resource deleted successfully'
    ): JsonResponse {
        return self::success(null, $message, 200);
    }

    /**
     * No content response (204)
     *
     * @return JsonResponse
     */
    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Validation error response (422)
     *
     * @param array $errors Validation errors
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function validationError(
        array $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return self::error($message, 422, $errors);
    }

    /**
     * Unauthorized response (401)
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function unauthorized(
        string $message = 'Unauthorized'
    ): JsonResponse {
        return self::error($message, 401);
    }

    /**
     * Forbidden response (403)
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function forbidden(
        string $message = 'Forbidden'
    ): JsonResponse {
        return self::error($message, 403);
    }

    /**
     * Not found response (404)
     *
     * @param string $message Error message
     * @return JsonResponse
     */
    public static function notFound(
        string $message = 'Resource not found'
    ): JsonResponse {
        return self::error($message, 404);
    }

    /**
     * Server error response (500)
     *
     * @param string $message Error message
     * @param mixed $errors Additional error details (only in debug mode)
     * @return JsonResponse
     */
    public static function serverError(
        string $message = 'Internal server error',
        $errors = null
    ): JsonResponse {
        // Only include error details in debug mode
        if (!config('app.debug')) {
            $errors = null;
        }

        return self::error($message, 500, $errors);
    }

    /**
     * Too many requests response (429)
     *
     * @param string $message Error message
     * @param int $retryAfter Seconds until retry is allowed
     * @return JsonResponse
     */
    public static function tooManyRequests(
        string $message = 'Too many requests',
        int $retryAfter = 60
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'code' => 429,
            'message' => $message,
            'meta' => [
                'retry_after' => $retryAfter,
            ],
        ], 429)->header('Retry-After', $retryAfter);
    }

    /**
     * Custom response with meta
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param array $meta Additional metadata
     * @param int $code HTTP status code
     * @return JsonResponse
     */
    public static function withMeta(
        $data,
        string $message = 'Success',
        array $meta = [],
        int $code = 200
    ): JsonResponse {
        $response = [
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }
}
