<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class SystemController extends Controller
{
    /**
     * Download Database Backup
     * 
     * SECURITY FIXES:
     * - Uses config() instead of env() (works with config:cache)
     * - Uses Laravel Process for safer command execution
     * - Password not exposed in process list
     * - Sanitized error messages
     */
    public function backupDatabase(Request $request)
    {
        try {
            // Create backup directory if not exists
            $backupDir = storage_path('app/backups');
            if (! file_exists($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $filename = 'backup_'.date('Y-m-d_His').'.sql';
            $filepath = $backupDir.'/'.$filename;

            // SECURITY FIX: Use config() instead of env() - works with config:cache
            $host = config('database.connections.pgsql.host', '127.0.0.1');
            $port = config('database.connections.pgsql.port', '5432');
            $database = config('database.connections.pgsql.database');
            $username = config('database.connections.pgsql.username');
            $password = config('database.connections.pgsql.password');

            if (!$database || !$username) {
                throw new \Exception('Database configuration is incomplete.');
            }

            // SECURITY FIX: Use Laravel Process with environment variable for password
            // This prevents password exposure in process list (ps aux)
            $result = Process::env([
                'PGPASSWORD' => $password,
            ])->run([
                'pg_dump',
                '-h', $host,
                '-p', $port,
                '-U', $username,
                '-F', 'p',
                '-f', $filepath,
                $database,
            ]);

            if ($result->failed()) {
                // SECURITY: Don't expose raw error output to user
                Log::error('Database backup failed', [
                    'exit_code' => $result->exitCode(),
                    'error' => $result->errorOutput(),
                ]);
                throw new \Exception('Backup process failed. Check server logs for details.');
            }

            // Verify file was created
            if (!file_exists($filepath) || filesize($filepath) === 0) {
                throw new \Exception('Backup file was not created successfully.');
            }

            // Log activity
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'database_backup',
                'description' => 'Downloaded database backup',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            // Return file download
            return response()->download($filepath, $filename)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Backup error', ['message' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Backup gagal. Silakan hubungi administrator.',
            ], 500);
        }
    }

    /**
     * Toggle Maintenance Mode
     * 
     * SECURITY FIX: Use configurable secret instead of hardcoded
     */
    public function toggleMaintenanceMode(Request $request)
    {
        $enable = $request->input('enable', false);

        try {
            if ($enable) {
                // SECURITY FIX: Generate unique secret or use configured one
                $secret = config('app.maintenance_secret', Str::random(32));
                
                \Illuminate\Support\Facades\Artisan::call('down', [
                    '--secret' => $secret,
                ]);
                $status = 'enabled';
                
                // Store secret for super admin reference (encrypted in session/cache)
                cache()->put('maintenance_bypass_secret', $secret, now()->addHours(24));
            } else {
                \Illuminate\Support\Facades\Artisan::call('up');
                $status = 'disabled';
                cache()->forget('maintenance_bypass_secret');
            }

            // Log activity
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'maintenance_mode_'.$status,
                'description' => "Maintenance mode {$status}",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            $response = [
                'success' => true,
                'message' => "Maintenance mode {$status}",
                'data' => ['maintenance_mode' => $enable],
            ];
            
            // Include bypass URL for super admin when enabling
            if ($enable && isset($secret)) {
                $response['data']['bypass_url'] = url("/{$secret}");
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Maintenance mode toggle failed', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah mode maintenance.',
            ], 500);
        }
    }

    /**
     * Get Maintenance Mode Status
     */
    public function getMaintenanceStatus()
    {
        $isDown = app()->isDownForMaintenance();

        return response()->json([
            'success' => true,
            'data' => [
                'maintenance_mode' => $isDown,
                'status' => $isDown ? 'down' : 'up',
            ],
        ]);
    }
}
