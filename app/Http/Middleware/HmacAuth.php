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
        $apiKey = $request->header('X-API-KEY') ?? $request->header('X-API-Key');
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

        // Optional: check timestamp window (prevent replay attacks - allow 5 minutes)
        if (abs(time() - (int)$timestamp) > 300) {
            return response()->json([
                'error' => 'Timestamp too old'
            ], 401);
        }

        // Build signature string: timestamp.body
        $rawBody = $request->getContent();
        $dataToSign = $timestamp . '.' . $rawBody;

        // Calculate expected signature
        $expected = hash_hmac('sha256', $dataToSign, $shop->api_secret);

        // Compare signatures (timing-safe comparison)
        if (!hash_equals($expected, $signature)) {
            Log::warning('HMAC signature mismatch', [
                'api_key' => $apiKey,
                'expected' => $expected,
                'received' => $signature,
            ]);
            
            return response()->json([
                'error' => 'Invalid signature'
            ], 401);
        }

        // Attach shop to request attributes so controllers can use it
        $request->attributes->set('shop', $shop);

        return $next($request);
    }
}
