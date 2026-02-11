<?php

declare(strict_types=1);

namespace App\Application\Bus\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;

class TransactionMiddleware
{
    public function handle(object $command, Closure $next): mixed
    {
        // Skip transaction wrapping if command opts out
        if (method_exists($command, 'withoutTransaction') && $command->withoutTransaction()) {
            return $next($command);
        }

        return DB::transaction(fn () => $next($command));
    }
}
