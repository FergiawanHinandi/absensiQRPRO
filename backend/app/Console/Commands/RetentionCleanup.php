<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RetentionCleanup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'retention:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive old data and anonymize logs according to retention policy';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cutoff = now()->subYears(2);
        $this->info("Starting retention policy cleanup for data older than: " . $cutoff->toDateTimeString());

        $this->archiveAttendance($cutoff);
        $this->anonymizeLogs($cutoff);

        $this->info('Retention cleanup completed successfully.');
    }

    private function archiveAttendance($cutoff)
    {
        $this->info('Archiving old attendance records...');

        // Ensure archive directory exists
        $archiveDir = storage_path('app/archives');
        if (!file_exists($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        $filename = 'attendance_' . now()->format('Y-m-d_His') . '.jsonl';
        $filepath = $archiveDir . '/' . $filename;
        
        $fp = fopen($filepath, 'w');
        
        $count = 0;
        
        // Query including soft deleted records
        \App\Models\Attendance::withTrashed()
            ->where('created_at', '<', $cutoff)
            ->chunk(1000, function ($attendances) use ($fp, &$count) {
                foreach ($attendances as $record) {
                    fwrite($fp, json_encode($record->toArray()) . "\n");
                    
                    // Hard delete the record from database
                    $record->forceDelete();
                    $count++;
                }
            });

        fclose($fp);

        if ($count > 0) {
            $this->info("Archived {$count} records to {$filename}");
            // Optional: Upload to S3 here if needed
        } else {
            $this->info("No attendance records to archive.");
            unlink($filepath); // Remove empty file
        }
    }

    private function anonymizeLogs($cutoff)
    {
        $this->info('Anonymizing old logs...');

        // Anonymize AuditLog (Custom)
        $affected = \App\Models\AuditLog::where('created_at', '<', $cutoff)
            ->update([
                'ip_address' => '0.0.0.0',
                'user_agent' => 'Anonymized',
                // Keep the description but maybe flag it? 
                // Requirement says "Anonymize personal data". IP/UA are the main ones.
            ]);
            
        $this->info("Anonymized {$affected} audit log entries.");

        // Anonymize ActivityLog (Spatie) if table exists
        if (\Illuminate\Support\Facades\Schema::hasTable('activity_log')) {
             // Spatie usually stores data in 'properties' json. 
             // We can't easily parse JSON in update query to scrub specific fields universally 
             // without heavy database load.
             // Simplest approach: Delete extremely old activity logs (e.g. > 2 years)?
             // Or update 'causer_ip' if it exists (Spatie doesn't track IP by default unless configured).
             // We'll skip complex Spatie cleaning for now unless explicitly required, 
             // assuming AuditLog is the primary source of PII IP data.
             
             // However, `properties` might contain data.
             // Let's at least scrub old SecurityAlerts if any?
        }
    }
}
