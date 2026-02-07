<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * ApiResponseTrait - Standardized JSON Responses
 *
 * USAGE:
 * use App\Traits\ApiResponseTrait;
 *
 * class MyController extends Controller {
 *     use ApiResponseTrait;
 *
 *     public function index() {
 *         return $this->success($data, 'Data loaded successfully');
 *     }
 * }
 *
 * RESPONSE FORMATS:
 *
 * Success:
 * {
 *   "success": true,
 *   "message": "...",
 *   "data": {...}
 * }
 *
 * Error:
 * {
 *   "success": false,
 *   "message": "...",
 *   "errors": {...}  // Optional validation errors
 * }
 */
trait ApiResponseTrait
{
    /**
     * Success response
     *
     * @param mixed $data
     * @param string|null $message
     * @param int $statusCode
     * @return JsonResponse
     */
    protected function success(mixed $data = null, ?string $message = null, int $statusCode = 200): JsonResponse
    {
        $response = ['success' => true];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Created response (201)
     *
     * @param mixed $data
     * @param string|null $message
     * @return JsonResponse
     */
    protected function created(mixed $data = null, ?string $message = 'Data berhasil dibuat.'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    /**
     * Error response
     *
     * @param string $message
     * @param int $statusCode
     * @param array|null $errors
     * @return JsonResponse
     */
    protected function error(string $message, int $statusCode = 400, ?array $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Not found response (404)
     */
    protected function notFound(string $message = 'Data tidak ditemukan.'): JsonResponse
    {
        return $this->error($message, 404);
    }

    /**
     * Unauthorized response (401)
     */
    protected function unauthorized(string $message = 'Akses tidak diizinkan.'): JsonResponse
    {
        return $this->error($message, 401);
    }

    /**
     * Forbidden response (403)
     */
    protected function forbidden(string $message = 'Anda tidak memiliki akses.'): JsonResponse
    {
        return $this->error($message, 403);
    }

    /**
     * Validation error response (422)
     */
    protected function validationError(array $errors, string $message = 'Validasi gagal.'): JsonResponse
    {
        return $this->error($message, 422, $errors);
    }

    /**
     * Server error response (500)
     */
    protected function serverError(string $message = 'Terjadi kesalahan server.'): JsonResponse
    {
        return $this->error($message, 500);
    }

    /**
     * Service unavailable response (503)
     */
    protected function serviceUnavailable(string $message = 'Layanan sedang tidak tersedia.'): JsonResponse
    {
        return $this->error($message, 503);
    }

    /**
     * Paginated response
     *
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator
     * @param string|null $message
     * @return JsonResponse
     */
    protected function paginated($paginator, ?string $message = null): JsonResponse
    {
        $response = ['success' => true];

        if ($message !== null) {
            $response['message'] = $message;
        }

        $response['data'] = $paginator->items();
        $response['meta'] = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
        $response['links'] = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        return response()->json($response);
    }
}
