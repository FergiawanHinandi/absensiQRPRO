<?php

declare(strict_types=1);

namespace App\Application\Bus\Middleware;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ValidationMiddleware
{
    public function handle(object $command, Closure $next): mixed
    {
        // Commands can optionally implement validation rules
        if (method_exists($command, 'rules')) {
            $rules = $command->rules();

            if (! empty($rules)) {
                $data = $this->extractData($command);
                $validator = Validator::make($data, $rules);

                if ($validator->fails()) {
                    throw new ValidationException($validator);
                }
            }
        }

        return $next($command);
    }

    private function extractData(object $command): array
    {
        $data = [];

        try {
            $reflection = new \ReflectionClass($command);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isInitialized($command)) {
                    $data[$prop->getName()] = $prop->getValue($command);
                }
            }
        } catch (\Throwable) {
            // Fall back to casting if available
            if (method_exists($command, 'toArray')) {
                return $command->toArray();
            }
        }

        return $data;
    }
}
