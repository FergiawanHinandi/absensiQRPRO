<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ExportReportRequest;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Async Report Export Controller
 *
 * Handles asynchronous report generation with:
 * - Rate limiting (5 exports per hour per user)
 * - Queue-based processing
 * - Status tracking
 * - Temporary file storage with auto-cleanup
 */
class AsyncReportExportController extends Controller
{
    /**
     * Rate limit: exports per hour per user
     */
    private const RATE_LIMIT_EXPORTS = 5;

    private const RATE_LIMIT_DECAY_SECONDS = 3600; // 1 hour

    /**
     * Create a new report export job
     *
     * POST /api/v1/reports/export
     */
    public function create(ExportReportRequest $request): JsonResponse
    {
        $user = $request->user();

        // Check rate limit
        $rateLimitKey = "report_export:{$user->id}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT_EXPORTS)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = ceil($seconds / 60);

            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak permintaan export. Coba lagi dalam {$minutes} menit.",
                'data' => [
                    'retry_after_seconds' => $seconds,
                ],
            ], 429);
        }

        // Increment rate limiter
        RateLimiter::hit($rateLimitKey, self::RATE_LIMIT_DECAY_SECONDS);

        $validated = $request->validated();

        // Create export record
        $export = ReportExport::create([
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'type' => ReportExport::TYPE_ATTENDANCE,
            'format' => $validated['format'],
            'parameters' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'class_id' => $validated['class_id'] ?? null,
                'subject_id' => $validated['subject_id'] ?? null,
                'include_summary' => $validated['include_summary'] ?? false,
            ],
            'status' => ReportExport::STATUS_PENDING,
        ]);

        // ✅ TENANT SAFETY: Dispatch job with school_id for tenant context
        GenerateReportExport::dispatch($user->school_id, $export->id);

        return response()->json([
            'success' => true,
            'message' => 'Export sedang diproses. Silakan cek status secara berkala.',
            'data' => [
                'job_id' => $export->id,
                'status' => $export->status,
                'status_label' => $export->status_label,
                'created_at' => $export->created_at->toIso8601String(),
                'estimated_time' => '1-5 menit',
            ],
        ], 202); // 202 Accepted
    }

    /**
     * Get export job status
     *
     * GET /api/v1/reports/export/{job_id}/status
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $user = $request->user();

        $export = ReportExport::where('id', $jobId)
            ->where('user_id', $user->id) // Security: only own exports
            ->first();

        if (! $export) {
            return response()->json([
                'success' => false,
                'message' => 'Export tidak ditemukan.',
            ], 404);
        }

        $data = [
            'job_id' => $export->id,
            'status' => $export->status,
            'status_label' => $export->status_label,
            'progress' => $export->progress,
            'created_at' => $export->created_at->toIso8601String(),
        ];

        // Add additional info based on status
        if ($export->status === ReportExport::STATUS_COMPLETED) {
            $data['completed_at'] = $export->completed_at?->toIso8601String();
            $data['file_name'] = $export->file_name;
            $data['file_size'] = $export->file_size_human;
            $data['download_url'] = route('reports.export.download', $export->id);
            $data['expires_at'] = $export->expires_at?->toIso8601String();
        } elseif ($export->status === ReportExport::STATUS_FAILED) {
            $data['error_message'] = $export->error_message;
        } elseif ($export->status === ReportExport::STATUS_PROCESSING) {
            $data['started_at'] = $export->started_at?->toIso8601String();
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Download completed export
     *
     * GET /api/v1/reports/export/{job_id}/download
     *
     * @return JsonResponse|StreamedResponse
     */
    public function download(Request $request, string $jobId)
    {
        $user = $request->user();

        $export = ReportExport::where('id', $jobId)
            ->where('user_id', $user->id)
            ->first();

        if (! $export) {
            return response()->json([
                'success' => false,
                'message' => 'Export tidak ditemukan.',
            ], 404);
        }

        if (! $export->isReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Export belum siap untuk diunduh.',
                'data' => [
                    'status' => $export->status,
                    'progress' => $export->progress,
                ],
            ], 400);
        }

        if ($export->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'File export sudah kadaluarsa. Silakan buat export baru.',
            ], 410); // 410 Gone
        }

        // For S3, return redirect to temporary URL
        $disk = config('filesystems.default');
        if ($disk === 's3') {
            $url = Storage::disk('s3')->temporaryUrl(
                $export->file_path,
                now()->addMinutes(30)
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'download_url' => $url,
                    'expires_in_minutes' => 30,
                ],
            ]);
        }

        // For local storage, stream the file
        if (! Storage::exists($export->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan di server.',
            ], 404);
        }

        $mimeType = $export->format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return Storage::download(
            $export->file_path,
            $export->file_name,
            ['Content-Type' => $mimeType]
        );
    }

    /**
     * List user's export history
     *
     * GET /api/v1/reports/export/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $exports = ReportExport::forUser($user->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($export) {
                return [
                    'job_id' => $export->id,
                    'type' => $export->type,
                    'format' => $export->format,
                    'status' => $export->status,
                    'status_label' => $export->status_label,
                    'progress' => $export->progress,
                    'file_name' => $export->file_name,
                    'file_size' => $export->file_size_human,
                    'created_at' => $export->created_at->toIso8601String(),
                    'completed_at' => $export->completed_at?->toIso8601String(),
                    'expires_at' => $export->expires_at?->toIso8601String(),
                    'is_downloadable' => $export->isReady() && ! $export->isExpired(),
                ];
            });

        // Get remaining rate limit
        $rateLimitKey = "report_export:{$user->id}";
        $remaining = RateLimiter::remaining($rateLimitKey, self::RATE_LIMIT_EXPORTS);

        return response()->json([
            'success' => true,
            'data' => [
                'exports' => $exports,
                'rate_limit' => [
                    'limit' => self::RATE_LIMIT_EXPORTS,
                    'remaining' => $remaining,
                    'period' => 'per jam',
                ],
            ],
        ]);
    }

    /**
     * Cancel a pending export
     *
     * DELETE /api/v1/reports/export/{job_id}
     */
    public function cancel(Request $request, string $jobId): JsonResponse
    {
        $user = $request->user();

        $export = ReportExport::where('id', $jobId)
            ->where('user_id', $user->id)
            ->first();

        if (! $export) {
            return response()->json([
                'success' => false,
                'message' => 'Export tidak ditemukan.',
            ], 404);
        }

        // Can only cancel pending exports
        if ($export->status !== ReportExport::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya export dengan status pending yang dapat dibatalkan.',
            ], 400);
        }

        $export->markAsFailed('Dibatalkan oleh pengguna');

        return response()->json([
            'success' => true,
            'message' => 'Export berhasil dibatalkan.',
        ]);
    }
}
