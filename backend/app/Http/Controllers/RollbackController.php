<?php

namespace App\Http\Controllers;

use App\Services\AtomicRollbackService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RollbackController extends Controller
{
    private $rollbackService;

    public function __construct(AtomicRollbackService $rollbackService)
    {
        $this->rollbackService = $rollbackService;
    }

    /**
     * Execute rollback
     */
    public function executeRollback(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:database,storage,full',
            'backup_identifier' => 'required|string'
        ]);

        try {
            $result = $this->rollbackService->executeRollback(
                $request->type,
                $request->backup_identifier
            );

            return response()->json([
                'success' => true,
                'message' => 'Rollback executed successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rollback failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get rollback history
     */
    public function getHistory(): JsonResponse
    {
        try {
            $history = $this->rollbackService->getRollbackHistory();

            return response()->json([
                'success' => true,
                'data' => $history
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve rollback history: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available backups
     */
    public function getAvailableBackups(): JsonResponse
    {
        try {
            $databaseBackups = $this->getDatabaseBackups();
            $storageBackups = $this->getStorageBackups();

            return response()->json([
                'success' => true,
                'data' => [
                    'database' => $databaseBackups,
                    'storage' => $storageBackups
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve backups: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getDatabaseBackups(): array
    {
        $backupPath = storage_path('app/backups/database');
        $backups = [];

        if (is_dir($backupPath)) {
            $files = glob($backupPath . '/*.sql');
            foreach ($files as $file) {
                $filename = basename($file, '.sql');
                $backups[] = [
                    'identifier' => $filename,
                    'size' => filesize($file),
                    'created_at' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
        }

        return array_reverse($backups);
    }

    private function getStorageBackups(): array
    {
        $backupPath = storage_path('app/backups/storage');
        $backups = [];

        if (is_dir($backupPath)) {
            $files = glob($backupPath . '/*.zip');
            foreach ($files as $file) {
                $filename = basename($file, '.zip');
                $backups[] = [
                    'identifier' => $filename,
                    'size' => filesize($file),
                    'created_at' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
        }

        return array_reverse($backups);
    }
}
