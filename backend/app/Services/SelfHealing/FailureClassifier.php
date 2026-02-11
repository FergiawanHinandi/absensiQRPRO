<?php

namespace App\Services\SelfHealing;

use Throwable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

enum FailureType: string
{
    case TRANSIENT = 'transient';
    case RESOURCE_EXHAUSTION = 'resource_exhaustion';
    case DEPENDENCY_FAILURE = 'dependency_failure';
    case DATA_CORRUPTION = 'data_corruption';
    case UNKNOWN = 'unknown';
}

class FailureClassifier
{
    /**
     * Classify a failure based on exception and context
     */
    public function classify(Throwable $e, array $context = []): FailureType
    {
        // Data corruption (highest priority)
        if ($this->isDataCorruption($e, $context)) {
            return FailureType::DATA_CORRUPTION;
        }
        
        // Transient failures
        if ($this->isTransient($e)) {
            return FailureType::TRANSIENT;
        }
        
        // Resource exhaustion
        if ($this->isResourceExhaustion($e, $context)) {
            return FailureType::RESOURCE_EXHAUSTION;
        }
        
        // Dependency failures
        if ($this->isDependencyFailure($e)) {
            return FailureType::DEPENDENCY_FAILURE;
        }
        
        return FailureType::UNKNOWN;
    }
    
    /**
     * Check if failure is transient (can be retried)
     */
    private function isTransient(Throwable $e): bool
    {
        // Database deadlocks
        if ($e instanceof QueryException) {
            $errorCode = $e->getCode();
            
            // MySQL deadlock (1213) or lock wait timeout (1205)
            if (in_array($errorCode, ['40001', '1213', '1205'])) {
                return true;
            }
        }
        
        // Connection timeouts
        if (str_contains($e->getMessage(), 'timeout') || 
            str_contains($e->getMessage(), 'timed out')) {
            return true;
        }
        
        // Temporary network issues
        if (str_contains($e->getMessage(), 'Connection refused') ||
            str_contains($e->getMessage(), 'Connection reset')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if failure is due to resource exhaustion
     */
    private function isResourceExhaustion(Throwable $e, array $context): bool
    {
        // Out of memory
        if (str_contains($e->getMessage(), 'Allowed memory size') ||
            str_contains($e->getMessage(), 'Out of memory')) {
            return true;
        }
        
        // Redis memory
        if (($context['redis_memory_percent'] ?? 0) > 95) {
            return true;
        }
        
        // Database connection pool exhausted
        if (($context['db_connection_percent'] ?? 0) > 90) {
            return true;
        }
        
        // Disk full
        if (($context['disk_usage_percent'] ?? 0) > 95 ||
            str_contains($e->getMessage(), 'No space left on device') ||
            str_contains($e->getMessage(), 'ENOSPC')) {
            return true;
        }
        
        // Too many connections
        if ($e instanceof QueryException && 
            str_contains($e->getMessage(), 'Too many connections')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if failure is due to dependency unavailability
     */
    private function isDependencyFailure(Throwable $e): bool
    {
        // Redis unavailable
        if (str_contains($e->getMessage(), 'Connection to Redis') ||
            str_contains($e->getMessage(), 'Redis server went away')) {
            return true;
        }
        
        // Database unavailable
        if ($e instanceof QueryException && 
            (str_contains($e->getMessage(), 'server has gone away') ||
             str_contains($e->getMessage(), 'Connection refused'))) {
            return true;
        }
        
        // External service unavailable
        if (str_contains($e->getMessage(), 'Service Unavailable') ||
            str_contains($e->getMessage(), '503')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if failure indicates data corruption
     */
    private function isDataCorruption(Throwable $e, array $context): bool
    {
        // Duplicate QR nonce (replay attack)
        if ($context['duplicate_nonce'] ?? false) {
            return true;
        }
        
        // Tenant isolation breach
        if ($context['tenant_isolation_breach'] ?? false) {
            return true;
        }
        
        // Integrity constraint violation
        if ($e instanceof QueryException && 
            str_contains($e->getMessage(), 'Integrity constraint violation')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get recommended action for failure type
     */
    public function getRecommendedAction(FailureType $type): string
    {
        return match($type) {
            FailureType::TRANSIENT => 'retry_with_backoff',
            FailureType::RESOURCE_EXHAUSTION => 'auto_scale',
            FailureType::DEPENDENCY_FAILURE => 'circuit_breaker',
            FailureType::DATA_CORRUPTION => 'freeze_write',
            FailureType::UNKNOWN => 'alert_manual',
        };
    }
    
    /**
     * Get max retry attempts for failure type
     */
    public function getMaxRetries(FailureType $type): int
    {
        return match($type) {
            FailureType::TRANSIENT => 5,
            FailureType::RESOURCE_EXHAUSTION => 0, // Don't retry, scale instead
            FailureType::DEPENDENCY_FAILURE => 3,
            FailureType::DATA_CORRUPTION => 0, // Never retry
            FailureType::UNKNOWN => 1,
        };
    }
    
    /**
     * Get retry delay in milliseconds
     */
    public function getRetryDelay(FailureType $type, int $attempt): int
    {
        $baseDelay = match($type) {
            FailureType::TRANSIENT => 100,
            FailureType::DEPENDENCY_FAILURE => 1000,
            default => 0,
        };
        
        // Exponential backoff with jitter
        $exponentialDelay = $baseDelay * pow(2, $attempt - 1);
        $jitter = rand(0, (int)($exponentialDelay * 0.1));
        
        return $exponentialDelay + $jitter;
    }
}
