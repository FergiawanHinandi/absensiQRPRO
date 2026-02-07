<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security Headers Middleware
 *
 * Adds comprehensive HTTP security headers to all responses:
 * - XSS Protection
 * - Clickjacking Protection
 * - Content Type Sniffing Protection
 * - HSTS (HTTP Strict Transport Security)
 * - Content Security Policy
 * - Permissions Policy
 * - Request ID Tracking
 */
class SecurityHeaders
{
    /**
     * Security headers configuration
     */
    private array $securityHeaders = [
        // Prevent MIME type sniffing
        'X-Content-Type-Options' => 'nosniff',

        // Prevent clickjacking - deny all framing
        'X-Frame-Options' => 'DENY',

        // Legacy XSS protection (for older browsers)
        'X-XSS-Protection' => '1; mode=block',

        // Control referrer information - strictest policy
        'Referrer-Policy' => 'no-referrer',

        // Restrict browser features/APIs - disable all by default
        'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()',

        // Prevent DNS prefetching
        'X-DNS-Prefetch-Control' => 'off',

        // Prevent downloading in IE
        'X-Download-Options' => 'noopen',

        // Prevent Adobe cross-domain requests
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Add unique request ID for tracking/debugging
        if (! $request->hasHeader('X-Request-ID')) {
            $request->headers->set('X-Request-ID', uniqid('req_', true));
        }

        // Generate Nonce for CSP
        $nonce = Str::random(32);

        // Share nonce with Vite to automatically enable it for scripts/styles in Blade
        Vite::useCspNonce($nonce);

        $response = $next($request);

        // Apply defined security headers (X-Frame, X-Content-Type, etc.)
        foreach ($this->securityHeaders as $header => $value) {
            $response->headers->set($header, $value);
        }

        // HSTS (HTTP Strict Transport Security)
        // Only apply in production to avoid issues with local development
        if (app()->environment('production') || config('app.force_https', false)) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // Content Security Policy
        $response->headers->set('Content-Security-Policy', $this->buildCSP($nonce));

        // Echo back request ID for correlation
        $response->headers->set('X-Request-ID', $request->header('X-Request-ID'));

        return $response;
    }

    /**
     * Build Content Security Policy directives
     */
    private function buildCSP(string $nonce): string
    {
        $directives = [
            // Default: only allow same-origin
            "default-src 'self'",

            // Scripts: self + nonce-based + eval (for some JS libs)
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",

            // Styles: self + inline (for dynamic styles)
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",

            // Images: self + data URIs + HTTPS sources
            "img-src 'self' data: https:",

            // Fonts: self + data URIs + Google Fonts
            "font-src 'self' data: https://fonts.gstatic.com",

            // Connect: self + WebSockets for real-time features
            "connect-src 'self' ws: wss: ".$this->getAllowedConnectSources(),

            // Frame ancestors: deny all embedding
            "frame-ancestors 'none'",

            // Base URI: prevent base tag hijacking
            "base-uri 'self'",

            // Form actions: only allow same-origin
            "form-action 'self'",

            // Object/embed: disable plugins
            "object-src 'none'",

            // Upgrade insecure requests in production
            ...(app()->environment('production') ? ['upgrade-insecure-requests'] : []),
        ];

        return implode('; ', $directives);
    }

    /**
     * Get allowed connect sources based on environment
     */
    private function getAllowedConnectSources(): string
    {
        $sources = [];

        // Allow local development servers
        if (app()->environment('local', 'development')) {
            $sources[] = 'http://localhost:*';
            $sources[] = 'http://127.0.0.1:*';
        }

        // Add Reverb/WebSocket server if configured
        if (config('broadcasting.connections.reverb.host')) {
            $reverbHost = config('broadcasting.connections.reverb.host');
            $reverbPort = config('broadcasting.connections.reverb.port', 8080);
            $sources[] = "ws://{$reverbHost}:{$reverbPort}";
            $sources[] = "wss://{$reverbHost}:{$reverbPort}";
        }

        return implode(' ', $sources);
    }
}
