<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Logging\LogContext;
use App\Services\TenantContext;
use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;
use Monolog\LogRecord;

class StructuredJsonFormatter extends MonologJsonFormatter
{
    public function __construct()
    {
        parent::__construct();
    }

    public function format(LogRecord $record): string
    {
        $normalized = parent::format($record);

        // The parent produces JSON; we enrich it with context
        $data = json_decode($normalized, true);

        if (! is_array($data)) {
            return $normalized;
        }

        // Enrich with correlation IDs from LogContext
        $correlationContext = $this->getCorrelationContext();
        $tenantContextData = $this->getTenantContextData();

        $enriched = array_merge(
            [
                'timestamp' => $data['datetime'] ?? now()->toIso8601String(),
                'level' => $data['level_name'] ?? $record->level->name,
                'channel' => $data['channel'] ?? $record->channel,
                'message' => $data['message'] ?? $record->message,
            ],
            $correlationContext,
            $tenantContextData,
            [
                'environment' => config('app.env', 'production'),
                'hostname' => gethostname() ?: 'unknown',
                'context' => $data['context'] ?? $record->context,
            ]
        );

        if (! empty($data['extra'])) {
            $enriched['extra'] = $data['extra'];
        }

        return $this->toJson($enriched) . "\n";
    }

    private function getCorrelationContext(): array
    {
        try {
            if (class_exists(LogContext::class)) {
                return [
                    'request_id' => LogContext::getRequestId(),
                    'trace_id' => LogContext::getTraceId(),
                    'span_id' => LogContext::getCurrentSpanId(),
                ];
            }
        } catch (\Throwable) {
            // LogContext not available
        }

        return [];
    }

    private function getTenantContextData(): array
    {
        try {
            $tenant = app(TenantContext::class);
            if ($tenant->isSet()) {
                return [
                    'school_id' => $tenant->getSchoolIdOrNull(),
                    'user_id' => $tenant->getUserId(),
                ];
            }
        } catch (\Throwable) {
            // TenantContext not bound yet
        }

        return [];
    }
}
