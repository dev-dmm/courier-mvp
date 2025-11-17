<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;

class HmacAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        if (!$apiKey || !$timestamp || !$signature) {
            return response()->json([
                'error' => 'Missing authentication headers'
            ], 401);
        }

        // Find shop by API key
        $shop = Shop::where('api_key', $apiKey)
            ->where('is_active', true)
            ->first();

        if (!$shop) {
            return response()->json([
                'error' => 'Invalid API key'
            ], 401);
        }

        // Validate timestamp (prevent replay attacks - allow 5 minutes window)
        $requestTime = (int) $timestamp;
        $currentTime = time();
        if (abs($currentTime - $requestTime) > 300) {
            return response()->json([
                'error' => 'Request timestamp expired'
            ], 401);
        }

        // Build signature string
        $method = $request->method();
        $path = $request->path();
        $body = $request->getContent();
        $signatureString = $timestamp . $method . $path . $body;

        // Calculate expected signature
        $expectedSignature = hash_hmac('sha256', $signatureString, $shop->api_secret);

        // Compare signatures (timing-safe comparison)
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('HMAC signature mismatch', [
                'api_key' => $apiKey,
                'expected' => $expectedSignature,
                'received' => $signature,
            ]);
            
            return response()->json([
                'error' => 'Invalid signature'
            ], 401);
        }

        // Attach shop to request for use in controllers
        $request->merge(['shop' => $shop]);

        return $next($request);
    }
}
