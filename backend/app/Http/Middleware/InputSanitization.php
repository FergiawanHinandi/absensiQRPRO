<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InputSanitization
{
    /**
     * Handle an incoming request.
     * 
     * Applies global sanitization rules:
     * 1. Trim strings
     * 2. Strip standard HTML tags (Anti-XSS)
     * 3. Convert empty strings to null
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();

        if ($input) {
            array_walk_recursive($input, function (&$value) {
                if (is_string($value)) {
                    // 1. Trim whitespace
                    $value = trim($value);

                    // 2. Strip HTML tags (Basic XSS protection)
                    // We allow NO tags by default. If rich text is needed, it must be whitelisted per field in a specific service.
                    if ($value !== strip_tags($value)) {
                        $value = strip_tags($value);
                    }

                    // 3. Normalize empty strings
                    if ($value === '') {
                        $value = null;
                    }
                }
            });

            $request->merge($input);
        }

        return $next($request);
    }
}
