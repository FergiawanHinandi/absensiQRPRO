<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExportProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Export Progress Controller
 * 
 * Provides API endpoints for tracking export progress in real-time.
 * Frontend can poll these endpoints to show progress bars to users.
 */
class ExportProgressController extends Controller
{
    /**
     * Get export progress by ID.
     * 
     * @param int $id Export progress ID
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $exportProgress = ExportProgress::where('id', $id)
            ->where('user_id', auth()->id())
            ->where('school_id', auth()->user()->school_id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $exportProgress->id,
                'export_type' => $exportProgress->export_type,
                'status' => $exportProgress->status,
                'total_records' => $exportProgress->total_records,
                'processed_records' => $exportProgress->processed_records,
                'progress_percentage' => $exportProgress->progress_percentage,
                'filename' => $exportProgress->filename,
                'file_path' => $exportProgress->file_path,
                'download_url' => $exportProgress->file_path 
                    ? \Storage::disk('public')->url($exportProgress->file_path)
                    : null,
                'error_message' => $exportProgress->error_message,
                'started_at' => $exportProgress->started_at?->toIso8601String(),
                'completed_at' => $exportProgress->completed_at?->toIso8601String(),
                'is_processing' => $exportProgress->isProcessing(),
                'is_completed' => $exportProgress->isCompleted(),
                'is_failed' => $exportProgress->isFailed(),
            ],
        ]);
    }

    /**
     * Get all export progress for current user.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = ExportProgress::where('user_id', auth()->id())
            ->where('school_id', auth()->user()->school_id)
            ->orderBy('created_at', 'desc');

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by export type if provided
        if ($request->has('export_type')) {
            $query->where('export_type', $request->export_type);
        }

        $exports = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $exports->map(function ($export) {
                return [
                    'id' => $export->id,
                    'export_type' => $export->export_type,
                    'status' => $export->status,
                    'total_records' => $export->total_records,
                    'processed_records' => $export->processed_records,
                    'progress_percentage' => $export->progress_percentage,
                    'filename' => $export->filename,
                    'download_url' => $export->file_path 
                        ? \Storage::disk('public')->url($export->file_path)
                        : null,
                    'error_message' => $export->error_message,
                    'started_at' => $export->started_at?->toIso8601String(),
                    'completed_at' => $export->completed_at?->toIso8601String(),
                    'created_at' => $export->created_at->toIso8601String(),
                ];
            }),
            'meta' => [
                'current_page' => $exports->currentPage(),
                'last_page' => $exports->lastPage(),
                'per_page' => $exports->perPage(),
                'total' => $exports->total(),
            ],
        ]);
    }

    /**
     * Get active (processing) exports for current user.
     * 
     * @return JsonResponse
     */
    public function active(): JsonResponse
    {
        $activeExports = ExportProgress::where('user_id', auth()->id())
            ->where('school_id', auth()->user()->school_id())
            ->where('status', ExportProgress::STATUS_PROCESSING)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $activeExports->map(function ($export) {
                return [
                    'id' => $export->id,
                    'export_type' => $export->export_type,
                    'status' => $export->status,
                    'total_records' => $export->total_records,
                    'processed_records' => $export->processed_records,
                    'progress_percentage' => $export->progress_percentage,
                    'started_at' => $export->started_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Delete an export progress record.
     * Only allows deletion of completed or failed exports.
     * 
     * @param int $id Export progress ID
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $exportProgress = ExportProgress::where('id', $id)
            ->where('user_id', auth()->id())
            ->where('school_id', auth()->user()->school_id)
            ->firstOrFail();

        // Don't allow deletion of processing exports
        if ($exportProgress->isProcessing()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete export that is currently processing',
            ], 400);
        }

        // Delete the file if it exists
        if ($exportProgress->file_path) {
            \Storage::disk('public')->delete($exportProgress->file_path);
        }

        $exportProgress->delete();

        return response()->json([
            'success' => true,
            'message' => 'Export deleted successfully',
        ]);
    }
}
