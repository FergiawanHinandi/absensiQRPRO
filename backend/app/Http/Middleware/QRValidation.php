<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class QRValidation
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Validate Payload Structure
        if (!$request->has(['school_id', 'session_id', 'expires_at', 'signature'])) {
            throw new BadRequestHttpException('Invalid QR Payload Structure. Missing required fields.');
        }

        $payload = $request->only(['school_id', 'session_id', 'expires_at']);
        $providedSignature = $request->input('signature');
        $user = $request->user();

        // 2. Validate Signature
        // We assume the signature is created from the JSON representation of the payload order
        // Note: Ideally, pass the raw payload string to avoid JSON serialization differences
        // For this refactor, we reconstruct strictly sorted
        ksort($payload);
        $expectedSignature = hash_hmac(
            'sha256',
            json_encode($payload),
            config('app.key')
        );

        if (!hash_equals($expectedSignature, $providedSignature)) {
            Log::warning('Invalid QR Signature', [
                'user_id' => $user ? $user->id : 'unknown',
                'ip' => $request->ip(),
                'provided' => $providedSignature,
                'payload' => $payload
            ]);
            
            throw new BadRequestHttpException('Invalid QR Signature');
        }

        // 3. Validate Expiration (Max 60 seconds tolerance)
        // We use a small window because the QR rotates
        $expiresAt = (int) $payload['expires_at'];
        if (now()->timestamp > $expiresAt) {
            Log::info('QR Expired', [
                'user_id' => $user->id,
                'expires_at' => $expiresAt,
                'now' => now()->timestamp
            ]);
            throw new BadRequestHttpException('QR Code has expired.');
        }

        // 4. Validate School Match
        if ($user && (int)$payload['school_id'] !== (int)$user->school_id) {
            Log::warning('Cross-School QR Attempt', [
                'user_id' => $user->id,
                'user_school' => $user->school_id,
                'qr_school' => $payload['school_id']
            ]);
            throw new UnauthorizedException('QR Code is not for your school.');
        }

        // 5. Replay Attack Prevention (Basic)
        // Real replay prevention happens in the Service (firstOrCreate) or Cache Lock
        // Here we just ensure the QR itself isn't ancient (handled by expiry)
        // If strict nonce replay is needed, we would check Cache::has($signature)
        // But for Session QR, multiple students use same signature, so we don't block signature reuse globally.
        // We block usage *by the same student* in the Service.

        return $next($request);
    }
}
