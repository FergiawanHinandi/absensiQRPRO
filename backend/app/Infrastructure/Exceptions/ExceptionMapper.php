<?php

declare(strict_types=1);

namespace App\Infrastructure\Exceptions;

use App\Exceptions\AttendanceException;
use App\Exceptions\FailSecureException;
use App\Exceptions\InvalidQrException;
use App\Exceptions\QrExpiredException;
use App\Exceptions\QrValidationException;
use App\Exceptions\StateViolationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Centralized exception → HTTP response mapper.
 *
 * Provides consistent error response format across the entire API.
 * All domain exceptions are mapped to appropriate HTTP status codes
 * with machine-readable error codes.
 *
 * Response format:
 * {
 *   "error": {
 *     "code": "MACHINE_READABLE_CODE",
 *     "message": "Human-readable message",
 *     "details": { ... }  // optional
 *   }
 * }
 */
class ExceptionMapper
{
    /**
     * Domain exception → [status, code] mapping.
     */
    private array $map = [
        StateViolationException::class => ['status' => 409, 'code' => 'STATE_VIOLATION'],
        AttendanceException::class => ['status' => 422, 'code' => 'ATTENDANCE_ERROR'],
        InvalidQrException::class => ['status' => 400, 'code' => 'INVALID_QR'],
        QrExpiredException::class => ['status' => 410, 'code' => 'QR_EXPIRED'],
        QrValidationException::class => ['status' => 400, 'code' => 'QR_VALIDATION_FAILED'],
        FailSecureException::class => ['status' => 500, 'code' => 'SYSTEM_ERROR'],
    ];

    /**
     * Attempt to render a Throwable as a JSON response.
     *
     * Returns null if this exception is not mapped (so Laravel's
     * default handler can process it).
     */
    public function render(\Throwable $e): ?JsonResponse
    {
        // Domain exceptions
        foreach ($this->map as $exceptionClass => $config) {
            if ($e instanceof $exceptionClass) {
                return $this->respond(
                    status: $config['status'],
                    code: $config['code'],
                    message: $e->getMessage(),
                    details: method_exists($e, 'toArray') ? $e->toArray() : null,
                );
            }
        }

        // Laravel built-in exceptions
        if ($e instanceof ValidationException) {
            return $this->respond(
                status: 422,
                code: 'VALIDATION_ERROR',
                message: 'The given data was invalid.',
                details: ['errors' => $e->errors()],
            );
        }

        if ($e instanceof AuthenticationException) {
            return $this->respond(
                status: 401,
                code: 'UNAUTHENTICATED',
                message: 'You must be authenticated to access this resource.',
            );
        }

        if ($e instanceof AuthorizationException) {
            return $this->respond(
                status: 403,
                code: 'FORBIDDEN',
                message: $e->getMessage() ?: 'You are not authorized to perform this action.',
            );
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return $this->respond(
                status: 404,
                code: 'NOT_FOUND',
                message: 'The requested resource was not found.',
            );
        }

        if ($e instanceof HttpException) {
            return $this->respond(
                status: $e->getStatusCode(),
                code: $this->httpStatusToCode($e->getStatusCode()),
                message: $e->getMessage() ?: 'An error occurred.',
            );
        }

        // Unhandled — return null so Laravel's default handler processes it
        return null;
    }

    /**
     * Register a custom exception mapping.
     */
    public function registerMapping(string $exceptionClass, int $status, string $code): void
    {
        $this->map[$exceptionClass] = ['status' => $status, 'code' => $code];
    }

    private function respond(int $status, string $code, string $message, ?array $details = null): JsonResponse
    {
        $body = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $body['error']['details'] = $details;
        }

        return response()->json($body, $status);
    }

    private function httpStatusToCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            402 => 'SUBSCRIPTION_REQUIRED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            410 => 'GONE',
            422 => 'UNPROCESSABLE_ENTITY',
            429 => 'RATE_LIMITED',
            500 => 'INTERNAL_ERROR',
            502 => 'BAD_GATEWAY',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'ERROR',
        };
    }
}
