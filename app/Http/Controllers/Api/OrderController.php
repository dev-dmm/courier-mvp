<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Customer;
use App\Services\CustomerHashService;
use App\Services\CustomerStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function __construct(
        private CustomerHashService $hashService,
        private CustomerStatsService $statsService
    ) {}

    /**
     * Store a new order from WooCommerce
     */
    public function store(Request $request)
    {
        $shop = $request->attributes->get('shop');
        
        // GDPR Compliance: API now receives hashed PII, not raw data
        $validated = $request->validate([
            'external_order_id' => 'required|string',
            'customer_hash' => 'required|string|size:64', // SHA256 hash is 64 hex chars
            // Hashed PII fields (optional, for additional matching)
            'customer_phone_hash' => 'nullable|string|size:64',
            'customer_name_hash' => 'nullable|string|size:64',
            'shipping_address_line1_hash' => 'nullable|string|size:64',
            'shipping_address_line2_hash' => 'nullable|string|size:64',
            // Non-PII fields (safe to store)
            'shipping_city' => 'nullable|string',
            'shipping_postcode' => 'nullable|string',
            'shipping_country' => 'nullable|string',
            'total_amount' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'status' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'payment_method_title' => 'nullable|string',
            'shipping_method' => 'nullable|string',
            'items_count' => 'nullable|integer',
            'ordered_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'meta' => 'nullable|array',
        ]);

        try {
            DB::beginTransaction();

            // Customer hash is provided by the plugin (already hashed)
            $customerHash = $validated['customer_hash'];

            // Find or create customer (no raw PII stored)
            $customer = Customer::firstOrCreate(
                ['customer_hash' => $customerHash],
                [
                    // No raw PII stored - GDPR compliant
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]
            );

            // Update customer last seen
            $customer->update(['last_seen_at' => now()]);

            // Create or update order (only hashed PII stored)
            $order = Order::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'external_order_id' => $validated['external_order_id'],
                ],
                [
                    'customer_id' => $customer->id,
                    'customer_hash' => $customerHash,
                    // Store hashed PII only (no raw data)
                    'customer_name_hash' => $validated['customer_name_hash'] ?? null,
                    'customer_phone_hash' => $validated['customer_phone_hash'] ?? null,
                    'shipping_address_line1_hash' => $validated['shipping_address_line1_hash'] ?? null,
                    'shipping_address_line2_hash' => $validated['shipping_address_line2_hash'] ?? null,
                    // Non-PII fields (safe to store)
                    'shipping_city' => $validated['shipping_city'] ?? null,
                    'shipping_postcode' => $validated['shipping_postcode'] ?? null,
                    'shipping_country' => $validated['shipping_country'] ?? null,
                    'total_amount' => $validated['total_amount'] ?? null,
                    'currency' => $validated['currency'] ?? 'EUR',
                    'status' => $validated['status'] ?? 'pending',
                    'payment_method' => $validated['payment_method'] ?? null,
                    'payment_method_title' => $validated['payment_method_title'] ?? null,
                    'shipping_method' => $validated['shipping_method'] ?? null,
                    'items_count' => $validated['items_count'] ?? null,
                    'ordered_at' => isset($validated['ordered_at']) && !empty($validated['ordered_at'])
                        ? date('Y-m-d H:i:s', strtotime($validated['ordered_at']))
                        : now(),
                    'completed_at' => isset($validated['completed_at']) && !empty($validated['completed_at'])
                        ? date('Y-m-d H:i:s', strtotime($validated['completed_at']))
                        : null,
                    'meta' => $validated['meta'] ?? null,
                ]
            );

            // Update customer stats
            $stats = $this->statsService->updateStats($customerHash);

            DB::commit();

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'customer_hash' => $customerHash,
                'risk_score' => $stats->delivery_risk_score ?? 0,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order ingestion failed', [
                'error' => $e->getMessage(),
                'shop_id' => $shop->id,
                'data' => $validated,
            ]);

            return response()->json([
                'error' => 'Failed to process order',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get order details
     */
    public function show(Request $request, $id)
    {
        $shop = $request->attributes->get('shop');
        
        $order = Order::where('id', $id)
            ->where('shop_id', $shop->id)
            ->with(['customer', 'shop', 'vouchers'])
            ->firstOrFail();

        return response()->json($order);
    }
}
