<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;

class MultiTenantRestoreValidationService
{
    private $validationErrors = [];
    private $validationWarnings = [];
    private $restoreId;

    public function __construct()
    {
        $this->restoreId = 'restore_' . uniqid() . '_' . time();
    }

    /**
     * Validate backup file for multi-tenant restore
     */
    public function validateBackupForRestore(string $backupPath, int $targetSchoolId): array
    {
        $this->logValidation('START', "Starting validation for school_id: {$targetSchoolId}");

        try {
            // Validate backup file exists and is readable
            if (!$this->validateBackupFile($backupPath)) {
                return $this->buildValidationResult(false);
            }

            // Extract and validate SQL content
            $sqlContent = $this->extractSqlContent($backupPath);
            if (!$sqlContent) {
                $this->addError('BACKUP_EMPTY', 'Backup file is empty or corrupted');
                return $this->buildValidationResult(false);
            }

            // Validate school_id consistency
            $this->validateSchoolIdConsistency($sqlContent, $targetSchoolId);

            // Validate table structure
            $this->validateTableStructure($sqlContent);

            // Validate data integrity
            $this->validateDataIntegrity($sqlContent, $targetSchoolId);

            // Check for potential conflicts
            $this->checkForConflicts($sqlContent, $targetSchoolId);

            $isValid = empty($this->validationErrors);
            $this->logValidation($isValid ? 'SUCCESS' : 'FAILED', "Validation completed with " . count($this->validationErrors) . " errors");

            return $this->buildValidationResult($isValid);

        } catch (\Exception $e) {
            $this->addError('VALIDATION_EXCEPTION', 'Validation failed: ' . $e->getMessage());
            $this->logValidation('ERROR', 'Validation exception: ' . $e->getMessage());
            return $this->buildValidationResult(false);
        }
    }

    /**
     * Validate backup file exists and is readable
     */
    private function validateBackupFile(string $backupPath): bool
    {
        if (!file_exists($backupPath)) {
            $this->addError('FILE_NOT_FOUND', "Backup file not found: {$backupPath}");
            return false;
        }

        if (!is_readable($backupPath)) {
            $this->addError('FILE_NOT_READABLE', "Backup file is not readable: {$backupPath}");
            return false;
        }

        $fileSize = filesize($backupPath);
        if ($fileSize === 0) {
            $this->addError('FILE_EMPTY', 'Backup file is empty');
            return false;
        }

        $this->logValidation('FILE_VALID', "Backup file validated: {$fileSize} bytes");
        return true;
    }

    /**
     * Extract SQL content from backup file
     */
    private function extractSqlContent(string $backupPath): string
    {
        $content = file_get_contents($backupPath);
        
        if ($content === false) {
            $this->addError('FILE_READ_ERROR', 'Failed to read backup file');
            return '';
        }

        // Remove comments and clean SQL
        $content = preg_replace('/--.*$/m', '', $content);
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        
        return trim($content);
    }

    /**
     * Validate school_id consistency across all records
     */
    private function validateSchoolIdConsistency(string $sqlContent, int $targetSchoolId): void
    {
        $this->logValidation('SCHOOL_ID_CHECK', "Validating school_id consistency for target: {$targetSchoolId}");

        // Extract INSERT statements and check school_id
        preg_match_all('/INSERT\s+INTO\s+(\w+)\s*\((.*?)\)\s*VALUES\s*\((.*?)\)/i', $sqlContent, $matches, PREG_SET_ORDER);

        $tablesToCheck = ['attendances', 'students', 'teachers', 'classes', 'schools'];
        $inconsistentRecords = [];

        foreach ($matches as $match) {
            $tableName = strtolower($match[1]);
            
            if (!in_array($tableName, $tablesToCheck)) {
                continue;
            }

            $columns = array_map('trim', explode(',', $match[2]));
            $values = array_map('trim', explode(',', $match[3]));

            // Find school_id column index
            $schoolIdIndex = array_search('school_id', $columns);
            
            if ($schoolIdIndex !== false && isset($values[$schoolIdIndex])) {
                $recordSchoolId = $this->extractValue($values[$schoolIdIndex]);
                
                if ($recordSchoolId != $targetSchoolId) {
                    $inconsistentRecords[] = [
                        'table' => $tableName,
                        'expected_school_id' => $targetSchoolId,
                        'found_school_id' => $recordSchoolId
                    ];
                }
            }
        }

        if (!empty($inconsistentRecords)) {
            $this->addError('SCHOOL_ID_MISMATCH', 'School ID inconsistency detected', [
                'mismatch_count' => count($inconsistentRecords),
                'examples' => array_slice($inconsistentRecords, 0, 5)
            ]);
        } else {
            $this->logValidation('SCHOOL_ID_OK', 'All records have consistent school_id');
        }
    }

    /**
     * Validate table structure
     */
    private function validateTableStructure(string $sqlContent): void
    {
        $this->logValidation('STRUCTURE_CHECK', 'Validating table structure');

        // Check for required tables
        $requiredTables = ['attendances', 'students', 'teachers', 'classes', 'schools'];
        $foundTables = [];

        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sqlContent, $matches);
        
        foreach ($matches[1] as $table) {
            $foundTables[] = strtolower($table);
        }

        $missingTables = array_diff($requiredTables, $foundTables);
        
        if (!empty($missingTables)) {
            $this->addError('MISSING_TABLES', 'Required tables missing from backup', [
                'missing_tables' => $missingTables
            ]);
        }

        // Validate school_id column exists in tenant tables
        $tenantTables = ['attendances', 'students', 'teachers', 'classes'];
        
        foreach ($tenantTables as $table) {
            if (in_array($table, $foundTables)) {
                if (!$this->hasSchoolIdColumn($sqlContent, $table)) {
                    $this->addError('MISSING_SCHOOL_ID_COLUMN', "Table {$table} missing school_id column");
                }
            }
        }
    }

    /**
     * Validate data integrity
     */
    private function validateDataIntegrity(string $sqlContent, int $targetSchoolId): void
    {
        $this->logValidation('INTEGRITY_CHECK', 'Validating data integrity');

        // Check for foreign key violations
        $this->checkForeignKeyIntegrity($sqlContent, $targetSchoolId);

        // Check for duplicate records
        $this->checkDuplicateRecords($sqlContent, $targetSchoolId);

        // Validate data formats
        $this->validateDataFormats($sqlContent);
    }

    /**
     * Check for foreign key integrity
     */
    private function checkForeignKeyIntegrity(string $sqlContent, int $targetSchoolId): void
    {
        // Extract student references in attendances
        preg_match_all('/INSERT\s+INTO\s+attendances.*?VALUES\s*\((.*?)\)/i', $sqlContent, $attendanceMatches);
        
        $studentIds = [];
        preg_match_all('/INSERT\s+INTO\s+students.*?VALUES\s*\((.*?)\)/i', $sqlContent, $studentMatches);

        // Extract student IDs from student table
        foreach ($studentMatches[1] as $values) {
            $valueArray = array_map('trim', explode(',', $values));
            if (isset($valueArray[0])) {
                $studentIds[] = $this->extractValue($valueArray[0]);
            }
        }

        // Check attendance records reference valid students
        foreach ($attendanceMatches[1] as $values) {
            $valueArray = array_map('trim', explode(',', $values));
            if (isset($valueArray[1])) { // Assuming student_id is second column
                $attendanceStudentId = $this->extractValue($valueArray[1]);
                
                if (!in_array($attendanceStudentId, $studentIds)) {
                    $this->addWarning('FOREIGN_KEY_WARNING', "Attendance references non-existent student: {$attendanceStudentId}");
                }
            }
        }
    }

    /**
     * Check for duplicate records
     */
    private function checkDuplicateRecords(string $sqlContent, int $targetSchoolId): void
    {
        // Check for duplicate students within the same school
        preg_match_all('/INSERT\s+INTO\s+students.*?VALUES\s*\((.*?)\)/i', $sqlContent, $matches);
        
        $studentRecords = [];
        foreach ($matches[1] as $values) {
            $valueArray = array_map('trim', explode(',', $values));
            $studentKey = $this->extractValue($valueArray[0]) . '_' . $targetSchoolId;
            
            if (isset($studentRecords[$studentKey])) {
                $this->addWarning('DUPLICATE_STUDENT', "Duplicate student record detected: {$studentKey}");
            } else {
                $studentRecords[$studentKey] = true;
            }
        }
    }

    /**
     * Validate data formats
     */
    private function validateDataFormats(string $sqlContent): void
    {
        // Check email formats
        preg_match_all('/INSERT\s+INTO\s+(students|teachers).*?VALUES\s*\((.*?)\)/i', $sqlContent, $matches);
        
        foreach ($matches[2] as $values) {
            $valueArray = array_map('trim', explode(',', $values));
            
            foreach ($valueArray as $value) {
                $cleanValue = $this->extractValue($value);
                
                if (filter_var($cleanValue, FILTER_VALIDATE_EMAIL) === false && 
                    strpos($cleanValue, '@') !== false) {
                    $this->addWarning('INVALID_EMAIL', "Invalid email format detected: {$cleanValue}");
                }
            }
        }

        // Check date formats
        preg_match_all('/\'(\d{4}-\d{2}-\d{2})\'/', $sqlContent, $dateMatches);
        
        foreach ($dateMatches[1] as $date) {
            if (!\DateTime::createFromFormat('Y-m-d', $date)) {
                $this->addWarning('INVALID_DATE', "Invalid date format detected: {$date}");
            }
        }
    }

    /**
     * Check for potential conflicts with existing data
     */
    private function checkForConflicts(string $sqlContent, int $targetSchoolId): void
    {
        $this->logValidation('CONFLICT_CHECK', 'Checking for conflicts with existing data');

        try {
            // Check if school exists
            $schoolExists = DB::table('schools')
                ->where('id', $targetSchoolId)
                ->exists();

            if (!$schoolExists) {
                $this->addError('SCHOOL_NOT_EXISTS', "Target school {$targetSchoolId} does not exist");
                return;
            }

            // Check for conflicting student IDs
            $existingStudentIds = DB::table('students')
                ->where('school_id', $targetSchoolId)
                ->pluck('id')
                ->toArray();

            preg_match_all('/INSERT\s+INTO\s+students.*?VALUES\s*\((.*?)\)/i', $sqlContent, $matches);
            
            foreach ($matches[1] as $values) {
                $valueArray = array_map('trim', explode(',', $values));
                if (isset($valueArray[0])) {
                    $backupStudentId = $this->extractValue($valueArray[0]);
                    
                    if (in_array($backupStudentId, $existingStudentIds)) {
                        $this->addWarning('STUDENT_ID_CONFLICT', "Student ID conflict: {$backupStudentId} already exists");
                    }
                }
            }

        } catch (QueryException $e) {
            $this->addError('DB_QUERY_ERROR', 'Database query failed during conflict check: ' . $e->getMessage());
        }
    }

    /**
     * Check if table has school_id column
     */
    private function hasSchoolIdColumn(string $sqlContent, string $tableName): bool
    {
        preg_match("/CREATE\s+TABLE.*?`?{$tableName}`?\s*\((.*?)\)/is", $sqlContent, $tableMatch);
        
        if (isset($tableMatch[1])) {
            return strpos(strtolower($tableMatch[1]), 'school_id') !== false;
        }
        
        return false;
    }

    /**
     * Extract clean value from SQL
     */
    private function extractValue(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B'");
        return $value;
    }

    /**
     * Add validation error
     */
    private function addError(string $code, string $message, array $details = null): void
    {
        $this->validationErrors[] = [
            'code' => $code,
            'message' => $message,
            'details' => $details,
            'timestamp' => now()->toISOString()
        ];

        $this->logValidation('ERROR', "{$code}: {$message}");
    }

    /**
     * Add validation warning
     */
    private function addWarning(string $code, string $message, array $details = null): void
    {
        $this->validationWarnings[] = [
            'code' => $code,
            'message' => $message,
            'details' => $details,
            'timestamp' => now()->toISOString()
        ];

        $this->logValidation('WARNING', "{$code}: {$message}");
    }

    /**
     * Log validation step
     */
    private function logValidation(string $step, string $message): void
    {
        Log::channel('rollback')->info("Multi-Tenant Restore Validation [{$this->restoreId}] {$step}: {$message}");
    }

    /**
     * Build validation result
     */
    private function buildValidationResult(bool $isValid): array
    {
        return [
            'valid' => $isValid,
            'restore_id' => $this->restoreId,
            'errors' => $this->validationErrors,
            'warnings' => $this->validationWarnings,
            'error_count' => count($this->validationErrors),
            'warning_count' => count($this->validationWarnings),
            'summary' => $isValid ? 'Validation passed' : 'Validation failed',
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Generate validation report
     */
    public function generateValidationReport(array $validationResult): string
    {
        $report = "# Multi-Tenant Restore Validation Report\n\n";
        $report .= "**Restore ID:** {$validationResult['restore_id']}\n";
        $report .= "**Timestamp:** {$validationResult['timestamp']}\n";
        $report .= "**Status:** " . ($validationResult['valid'] ? '✅ PASSED' : '❌ FAILED') . "\n\n";

        if (!empty($validationResult['errors'])) {
            $report .= "## Errors ({$validationResult['error_count']})\n\n";
            foreach ($validationResult['errors'] as $error) {
                $report .= "- **{$error['code']}:** {$error['message']}\n";
                if ($error['details']) {
                    $report .= "  - Details: " . json_encode($error['details'], JSON_PRETTY_PRINT) . "\n";
                }
            }
            $report .= "\n";
        }

        if (!empty($validationResult['warnings'])) {
            $report .= "## Warnings ({$validationResult['warning_count']})\n\n";
            foreach ($validationResult['warnings'] as $warning) {
                $report .= "- **{$warning['code']}:** {$warning['message']}\n";
                if ($warning['details']) {
                    $report .= "  - Details: " . json_encode($warning['details'], JSON_PRETTY_PRINT) . "\n";
                }
            }
            $report .= "\n";
        }

        $report .= "## Summary\n\n";
        $report .= $validationResult['summary'] . "\n";

        return $report;
    }
}
