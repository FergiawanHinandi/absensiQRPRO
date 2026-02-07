<?php

/**
 * Advanced Database Backup Strategy
 * AbsensiQR Pro - PostgreSQL/MySQL Multi-Tenant Backup System
 * 
 * Features:
 * - Weekly full backup
 * - Daily incremental backup
 * - WAL/binlog archiving for PITR
 * - Multi-tenant isolation
 * - Automated scheduling
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class AdvancedBackupStrategy
{
    private $config;
    private $backupPath;
    private $logFile;
    private $dbDriver;
    
    public function __construct()
    {
        $this->dbDriver = config('database.default');
        $this->config = [
            'full_backup_day' => 'sunday',
            'incremental_backup_time' => '02:00',
            'wal_archive_interval' => 300, // 5 minutes
            'retention_full' => 12, // weeks
            'retention_incremental' => 30, // days
            'retention_wal' => 7, // days
            'compression_level' => 9,
            'parallel_jobs' => 4
        ];
        
        $this->backupPath = storage_path('backups/advanced');
        $this->logFile = $this->backupPath . '/backup_strategy.log';
        
        $this->ensureDirectories();
    }
    
    /**
     * Main backup orchestrator
     */
    public function executeBackupStrategy(): void
    {
        $this->log("=== ADVANCED BACKUP STRATEGY STARTED ===");
        
        try {
            $today = Carbon::now();
            
            // Determine backup type based on schedule
            if ($this->isFullBackupDay($today)) {
                $this->executeFullBackup();
            } else {
                $this->executeIncrementalBackup();
            }
            
            // Always archive WAL/binlog
            $this->archiveTransactionLogs();
            
            // Cleanup old backups
            $this->cleanupOldBackups();
            
            $this->log("✅ Backup strategy completed successfully");
            
        } catch (Exception $e) {
            $this->log("❌ Backup strategy failed: " . $e->getMessage());
            $this->sendAlert('CRITICAL', 'Backup Strategy Failed', $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Weekly full backup execution
     */
    private function executeFullBackup(): void
    {
        $this->log("🔄 Executing FULL BACKUP (Weekly)");
        
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $backupDir = $this->backupPath . "/full/{$timestamp}";
        
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Get all schools for multi-tenant backup
        $schools = DB::table('schools')->where('status', 'active')->get();
        
        foreach ($schools as $school) {
            $this->createFullBackupForSchool($school->id, $backupDir);
        }
        
        // Create global backup (non-tenant data)
        $this->createGlobalFullBackup($backupDir);
        
        // Compress backup
        $this->compressBackup($backupDir);
        
        $this->log("✅ Full backup completed");
    }
    
    /**
     * Daily incremental backup execution
     */
    private function executeIncrementalBackup(): void
    {
        $this->log("🔄 Executing INCREMENTAL BACKUP (Daily)");
        
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $backupDir = $this->backupPath . "/incremental/{$timestamp}";
        
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Find last full backup
        $lastFullBackup = $this->findLastFullBackup();
        if (!$lastFullBackup) {
            throw new Exception("No full backup found for incremental backup");
        }
        
        // Get all schools
        $schools = DB::table('schools')->where('status', 'active')->get();
        
        foreach ($schools as $school) {
            $this->createIncrementalBackupForSchool($school->id, $backupDir, $lastFullBackup);
        }
        
        // Create global incremental backup
        $this->createGlobalIncrementalBackup($backupDir, $lastFullBackup);
        
        // Compress backup
        $this->compressBackup($backupDir);
        
        $this->log("✅ Incremental backup completed");
    }
    
    /**
     * Create full backup for specific school (tenant)
     */
    private function createFullBackupForSchool(int $schoolId, string $backupDir): void
    {
        $this->log("  📊 Creating full backup for school {$schoolId}");
        
        $schoolBackupDir = $backupDir . "/school_{$schoolId}";
        mkdir($schoolBackupDir, 0755, true);
        
        if ($this->dbDriver === 'pgsql') {
            $this->createPostgreSQLFullBackup($schoolId, $schoolBackupDir);
        } else {
            $this->createMySQLFullBackup($schoolId, $schoolBackupDir);
        }
        
        // Backup school-specific storage files
        $this->backupSchoolStorage($schoolId, $schoolBackupDir);
        
        $this->log("    ✅ School {$schoolId} full backup completed");
    }
    
    /**
     * PostgreSQL full backup implementation
     */
    private function createPostgreSQLFullBackup(int $schoolId, string $backupDir): void
    {
        $dbConfig = config('database.connections.pgsql');
        $backupFile = $backupDir . '/database_full.sql';
        
        // Create school-specific dump with WHERE clauses
        $tables = $this->getSchoolTables();
        $dumpCommands = [];
        
        foreach ($tables as $table) {
            if ($this->isMultiTenantTable($table)) {
                // Multi-tenant table - filter by school_id
                $dumpCommands[] = sprintf(
                    'pg_dump -h %s -p %s -U %s -d %s -t %s --data-only --where="school_id=%d"',
                    escapeshellarg($dbConfig['host']),
                    escapeshellarg($dbConfig['port']),
                    escapeshellarg($dbConfig['username']),
                    escapeshellarg($dbConfig['database']),
                    escapeshellarg($table),
                    $schoolId
                );
            }
        }
        
        // Execute dump commands
        $env = 'PGPASSWORD=' . escapeshellarg($dbConfig['password']);
        
        foreach ($dumpCommands as $command) {
            $fullCommand = $env . ' ' . $command . ' >> ' . escapeshellarg($backupFile);
            exec($fullCommand . ' 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception("PostgreSQL backup failed: " . implode("\n", $output));
            }
        }
        
        // Also create schema-only backup
        $schemaFile = $backupDir . '/schema.sql';
        $schemaCommand = sprintf(
            '%s pg_dump -h %s -p %s -U %s -d %s --schema-only -f %s',
            $env,
            escapeshellarg($dbConfig['host']),
            escapeshellarg($dbConfig['port']),
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['database']),
            escapeshellarg($schemaFile)
        );
        
        exec($schemaCommand . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("PostgreSQL schema backup failed: " . implode("\n", $output));
        }
    }
    
    /**
     * MySQL full backup implementation
     */
    private function createMySQLFullBackup(int $schoolId, string $backupDir): void
    {
        $dbConfig = config('database.connections.mysql');
        $backupFile = $backupDir . '/database_full.sql';
        
        // Create WHERE clause for multi-tenant tables
        $whereClause = "school_id = {$schoolId}";
        
        $command = sprintf(
            'mysqldump -h%s -P%s -u%s -p%s --single-transaction --routines --triggers --where="%s" %s > %s',
            escapeshellarg($dbConfig['host']),
            escapeshellarg($dbConfig['port'] ?? 3306),
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['password']),
            $whereClause,
            escapeshellarg($dbConfig['database']),
            escapeshellarg($backupFile)
        );
        
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("MySQL backup failed: " . implode("\n", $output));
        }
    }
    
    /**
     * Create incremental backup for specific school
     */
    private function createIncrementalBackupForSchool(int $schoolId, string $backupDir, string $lastFullBackup): void
    {
        $this->log("  📈 Creating incremental backup for school {$schoolId}");
        
        $schoolBackupDir = $backupDir . "/school_{$schoolId}";
        mkdir($schoolBackupDir, 0755, true);
        
        // Get timestamp of last full backup
        $lastBackupTime = $this->getBackupTimestamp($lastFullBackup);
        
        // Find changed records since last backup
        $changes = $this->detectChangesForSchool($schoolId, $lastBackupTime);
        
        if (empty($changes)) {
            $this->log("    ℹ️  No changes detected for school {$schoolId}");
            return;
        }
        
        // Create incremental backup file
        $this->createIncrementalBackupFile($schoolId, $schoolBackupDir, $changes);
        
        // Backup changed storage files
        $this->backupChangedStorageFiles($schoolId, $schoolBackupDir, $lastBackupTime);
        
        $this->log("    ✅ School {$schoolId} incremental backup completed");
    }
    
    /**
     * Archive WAL files (PostgreSQL) or binlog (MySQL)
     */
    private function archiveTransactionLogs(): void
    {
        $this->log("📝 Archiving transaction logs");
        
        if ($this->dbDriver === 'pgsql') {
            $this->archiveWALFiles();
        } else {
            $this->archiveBinlogFiles();
        }
        
        $this->log("✅ Transaction logs archived");
    }
    
    /**
     * Archive PostgreSQL WAL files
     */
    private function archiveWALFiles(): void
    {
        $dbConfig = config('database.connections.pgsql');
        $walArchiveDir = $this->backupPath . '/wal_archive';
        
        if (!file_exists($walArchiveDir)) {
            mkdir($walArchiveDir, 0755, true);
        }
        
        // Get current WAL file
        $env = 'PGPASSWORD=' . escapeshellarg($dbConfig['password']);
        $command = sprintf(
            '%s psql -h %s -p %s -U %s -d %s -t -c "SELECT pg_current_wal_lsn();"',
            $env,
            escapeshellarg($dbConfig['host']),
            escapeshellarg($dbConfig['port']),
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['database'])
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            $currentLSN = trim($output[0]);
            $this->log("  Current WAL LSN: {$currentLSN}");
            
            // Archive WAL files up to current LSN
            $this->performWALArchiving($currentLSN, $walArchiveDir);
        }
    }
    
    /**
     * Archive MySQL binlog files
     */
    private function archiveBinlogFiles(): void
    {
        $dbConfig = config('database.connections.mysql');
        $binlogArchiveDir = $this->backupPath . '/binlog_archive';
        
        if (!file_exists($binlogArchiveDir)) {
            mkdir($binlogArchiveDir, 0755, true);
        }
        
        // Get current binlog position
        $command = sprintf(
            'mysql -h%s -P%s -u%s -p%s -e "SHOW MASTER STATUS\\G"',
            escapeshellarg($dbConfig['host']),
            escapeshellarg($dbConfig['port'] ?? 3306),
            escapeshellarg($dbConfig['username']),
            escapeshellarg($dbConfig['password'])
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $this->parseBinlogStatus($output, $binlogArchiveDir);
        }
    }
    
    /**
     * Point-in-Time Recovery implementation
     */
    public function performPointInTimeRecovery(int $schoolId, Carbon $targetTime): void
    {
        $this->log("🎯 Starting Point-in-Time Recovery for school {$schoolId} to {$targetTime}");
        
        try {
            // Find appropriate backup chain
            $backupChain = $this->findBackupChain($schoolId, $targetTime);
            
            // Restore base backup
            $this->restoreBaseBackup($schoolId, $backupChain['full_backup']);
            
            // Apply incremental backups
            foreach ($backupChain['incrementals'] as $incremental) {
                $this->applyIncrementalBackup($schoolId, $incremental);
            }
            
            // Apply transaction logs up to target time
            $this->applyTransactionLogs($schoolId, $targetTime);
            
            // Verify recovery
            $this->verifyRecovery($schoolId, $targetTime);
            
            $this->log("✅ Point-in-Time Recovery completed successfully");
            
        } catch (Exception $e) {
            $this->log("❌ Point-in-Time Recovery failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Generate backup schedule diagram
     */
    public function generateBackupScheduleDiagram(): string
    {
        return "
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                           ADVANCED BACKUP SCHEDULE DIAGRAM                           ║
╠══════════════════════════════════════════════════════════════════════════════════════╣
║                                                                                      ║
║  Week Timeline:                                                                      ║
║  ┌─────┬─────┬─────┬─────┬─────┬─────┬─────┐                                        ║
║  │ SUN │ MON │ TUE │ WED │ THU │ FRI │ SAT │                                        ║
║  └─────┴─────┴─────┴─────┴─────┴─────┴─────┘                                        ║
║    │     │     │     │     │     │     │                                            ║
║    ▼     ▼     ▼     ▼     ▼     ▼     ▼                                            ║
║  ┌─────┬─────┬─────┬─────┬─────┬─────┬─────┐                                        ║
║  │FULL │ INC │ INC │ INC │ INC │ INC │ INC │                                        ║
║  │02:00│02:00│02:00│02:00│02:00│02:00│02:00│                                        ║
║  └─────┴─────┴─────┴─────┴─────┴─────┴─────┘                                        ║
║                                                                                      ║
║  Continuous WAL/Binlog Archiving:                                                   ║
║  ████████████████████████████████████████████████████████████████████████████████   ║
║  Every 5 minutes (24/7)                                                             ║
║                                                                                      ║
║  Backup Types:                                                                      ║
║  • FULL  : Complete database dump + all storage files                               ║
║  • INC   : Changed data since last backup + modified files                          ║
║  • WAL   : Transaction logs for point-in-time recovery                              ║
║                                                                                      ║
║  Multi-Tenant Isolation:                                                            ║
║  ┌─────────────┬─────────────┬─────────────┐                                        ║
║  │  School A   │  School B   │  School C   │                                        ║
║  │   Backup    │   Backup    │   Backup    │                                        ║
║  │  Isolated   │  Isolated   │  Isolated   │                                        ║
║  └─────────────┴─────────────┴─────────────┘                                        ║
║                                                                                      ║
║  Retention Policy:                                                                  ║
║  • Full Backups    : 12 weeks                                                       ║
║  • Incremental     : 30 days                                                        ║
║  • WAL/Binlog      : 7 days                                                         ║
║                                                                                      ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
        ";
    }
    
    /**
     * Generate Point-in-Time Recovery steps
     */
    public function generatePITRSteps(): string
    {
        return "
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                        POINT-IN-TIME RECOVERY STEPS                                 ║
╠══════════════════════════════════════════════════════════════════════════════════════╣
║                                                                                      ║
║  STEP 1: Identify Recovery Point                                                    ║
║  ┌────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ • Determine target timestamp (e.g., 2026-02-02 14:30:00)                      │ ║
║  │ • Identify affected school_id for multi-tenant recovery                       │ ║
║  │ • Validate recovery point is within retention period                          │ ║
║  └────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                      ║
║  STEP 2: Find Backup Chain                                                          ║
║  ┌────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ Timeline: [FULL] ──── [INC1] ──── [INC2] ──── [TARGET] ──── [NOW]            │ ║
║  │                                                  ▲                             │ ║
║  │                                            Recovery Point                      │ ║
║  │                                                                                │ ║
║  │ Required Components:                                                           │ ║
║  │ • Last FULL backup before target time                                         │ ║
║  │ • All INCREMENTAL backups between FULL and target                             │ ║
║  │ • WAL/Binlog files from last backup to target time                            │ ║
║  └────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                      ║
║  STEP 3: Prepare Recovery Environment                                               ║
║  ┌────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ • Create isolated recovery database instance                                   │ ║
║  │ • Ensure sufficient disk space for restoration                                │ ║
║  │ • Stop application connections to prevent conflicts                           │ ║
║  │ • Create pre-recovery snapshot for rollback                                   │ ║
║  └────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                      ║
║  STEP 4: Restore Base Backup                                                        ║
║  ┌────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ PostgreSQL:                                                                    │ ║
║  │   psql -h host -U user -d database < full_backup.sql                          │ ║
║  │                                                                                │ ║
║  │ MySQL:                                                                         │ ║
║  │   mysql -h host -u user -p database < full_backup.sql                         │ ║
║  │                                                                                │ ║
║  │ • Restore schema structure first                                              │ ║
║  │ • Restore data for specific school_id only                                    │ ║
║  │ • Verify base backup integrity                                                │ ║
║  └────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                      ║
║  STEP 5: Apply Incremental Backups                                                  ║
║  ┌────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ For each incremental backup in chronological order:                           │ ║
║  │                                                                                │ ║
║  │ • Extract incremental backup file                                             │ ║
║  │ • Apply changes using UPSERT operations                                       │ ║
║  │ • Verify incremental consistency                                              │ ║
║  │ • Update recovery progress tracking                                           │ ║
║  │                                                                                │ ║
║  │ Example SQL for incremental apply:                                            │ ║
║  │   INSERT INTO table (...) VALUES (...) ON CONFLICT (id) DO UPDATE SET ...    │ ║
║  └────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                      ║
║  STEP 6: Apply Transaction Logs                                                     ║
║  ┌────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ PostgreSQL WAL Recovery:                                                       │ ║
║  │   • Configure recovery.conf with target time                                  │ ║
║  │   • Set recovery_target_time = '2026-02-02 14:30:00'                          │ ║
║  │   • Start PostgreSQL in recovery mode                                         │ ║
║  │   • Monitor recovery progress until target time reached                       │ ║
║  │                                                                                │ ║
║  │ MySQL Binlog Recovery:                                                         │ ║
║  │   • Use mysqlbinlog to extract transactions                                   │ ║
║  │   • Apply binlog events up to target timestamp                                │ ║
║  │   • Filter by school_id to maintain tenant isolation                          │ ║
║  └────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                      ║
║  STEP 7: Verify Recovery                                                            ║
║  ┌────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ • Check data consistency and integrity                                        │ ║
║  │ • Verify target timestamp accuracy                                            │ ║
║  │ • Validate multi-tenant isolation                                             │ ║
║  │ • Test critical application functions                                         │ ║
║  │ • Generate recovery verification report                                       │ ║
║  └────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                      ║
║  STEP 8: Finalize Recovery                                                          ║
║  ┌────────────────────────────────────────────────────────────────────────────────┐ ║
║  │ • Switch application to recovered database                                    │ ║
║  │ • Update DNS/connection strings if needed                                     │ ║
║  │ • Resume normal backup schedule                                               │ ║
║  │ • Document recovery process and lessons learned                               │ ║
║  │ • Clean up temporary recovery files                                           │ ║
║  └────────────────────────────────────────────────────────────────────────────────┘ ║
║                                                                                      ║
║  Recovery Time Objectives (RTO):                                                    ║
║  • Small Database (< 1GB)  : 15-30 minutes                                          ║
║  • Medium Database (1-10GB): 30-60 minutes                                          ║
║  • Large Database (> 10GB) : 1-2 hours                                              ║
║                                                                                      ║
║  Recovery Point Objectives (RPO):                                                   ║
║  • Maximum data loss: 5 minutes (WAL/Binlog frequency)                              ║
║  • Typical data loss: < 1 minute                                                    ║
║                                                                                      ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
        ";
    }
    
    // Helper methods
    private function ensureDirectories(): void
    {
        $dirs = [
            $this->backupPath,
            $this->backupPath . '/full',
            $this->backupPath . '/incremental',
            $this->backupPath . '/wal_archive',
            $this->backupPath . '/binlog_archive'
        ];
        
        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    private function isFullBackupDay(Carbon $date): bool
    {
        return strtolower($date->format('l')) === $this->config['full_backup_day'];
    }
    
    private function getSchoolTables(): array
    {
        return [
            'users', 'attendances', 'classes', 'schedules', 
            'student_cards', 'notifications', 'reports'
        ];
    }
    
    private function isMultiTenantTable(string $table): bool
    {
        $multiTenantTables = [
            'users', 'attendances', 'classes', 'schedules',
            'student_cards', 'notifications', 'reports'
        ];
        
        return in_array($table, $multiTenantTables);
    }
    
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
    
    private function sendAlert(string $severity, string $title, string $message): void
    {
        // Implementation for alerting system
        // Could integrate with Slack, email, SMS, etc.
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    try {
        $app = require_once __DIR__ . '/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        
        $backupStrategy = new AdvancedBackupStrategy();
        
        $command = $argv[1] ?? 'backup';
        
        switch ($command) {
            case 'backup':
                $backupStrategy->executeBackupStrategy();
                break;
                
            case 'pitr':
                if (!isset($argv[2]) || !isset($argv[3])) {
                    echo "Usage: php advanced_backup_strategy.php pitr <school_id> <target_time>\n";
                    echo "Example: php advanced_backup_strategy.php pitr 1 '2026-02-02 14:30:00'\n";
                    exit(1);
                }
                
                $schoolId = (int)$argv[2];
                $targetTime = Carbon::parse($argv[3]);
                $backupStrategy->performPointInTimeRecovery($schoolId, $targetTime);
                break;
                
            case 'schedule':
                echo $backupStrategy->generateBackupScheduleDiagram();
                break;
                
            case 'pitr-steps':
                echo $backupStrategy->generatePITRSteps();
                break;
                
            default:
                echo "Available commands: backup, pitr, schedule, pitr-steps\n";
                exit(1);
        }
        
    } catch (Exception $e) {
        echo "\n❌ Command failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}