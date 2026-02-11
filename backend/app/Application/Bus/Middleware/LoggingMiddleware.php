<?php

declare(strict_types=1);

namespace App\Application\Bus\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class LoggingMiddleware
{
    public function handle(object $command, Closure $next): mixed
    {
        $commandClass = class_basename($command);
        $startTime = microtime(true);

        Log::channel('attendance_json')->info("Command dispatched: {$commandClass}", [
            'command' => $commandClass,
            'payload' => $this->sanitizePayload($command),
        ]);

        try {
            $result = $next($command);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('attendance_json')->info("Command completed: {$commandClass}", [
                'command' => $commandClass,
                'duration_ms' => $duration,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('attendance_json')->error("Command failed: {$commandClass}", [
                'command' => $commandClass,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            throw $e;
        }
    }

    private function sanitizePayload(object $command): array
    {
        $data = [];

        try {
            $reflection = new \ReflectionClass($command);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                $name = $prop->getName();
                $value = $prop->getValue($command);

                // Mask sensitive fields
                if (in_array($name, ['password', 'token', 'qrToken', 'secret'], true)) {
                    $data[$name] = '***REDACTED***';
                } else {
                    $data[$name] = $value;
                }
            }
        } catch (\Throwable) {
            $data['_error'] = 'Could not serialize command payload';
        }

        return $data;
    }
}
