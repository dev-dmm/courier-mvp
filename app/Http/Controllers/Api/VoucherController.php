<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\Order;
use App\Models\Customer;
use App\Services\CustomerHashService;
use App\Services\CustomerStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VoucherController extends Controller
{
    public function __construct(
        private CustomerHashService $hashService,
        private CustomerStatsService $statsService
    ) {}

    /**
     * Store a new voucher/tracking number
     */
    public function store(Request $request)
    {
        $shop = $request->attributes->get('shop');
        
        // GDPR Compliance: API now receives hashed customer_hash, not raw email
        $validated = $request->validate([
            'voucher_number' => 'required|string',
            'external_order_id' => 'nullable|string',
            'customer_hash' => 'nullable|string|size:64', // SHA256 hash is 64 hex chars
            'courier_name' => 'nullable|string',
            'courier_service' => 'nullable|string',
            'tracking_url' => 'nullable|url',
            'status' => 'nullable|string|in:created,shipped,in_transit,delivered,returned,failed',
            'shipped_at' => 'nullable|date',
            'delivered_at' => 'nullable|date',
            'returned_at' => 'nullable|date',
            'failed_at' => 'nullable|date',
            'meta' => 'nullable|array',
        ]);

        try {
            DB::beginTransaction();

            $order = null;
            $customer = null;
            $customerHash = null;

            // Try to find order if external_order_id provided
            if (isset($validated['external_order_id'])) {
                $order = Order::where('shop_id', $shop->id)
                    ->where('external_order_id', $validated['external_order_id'])
                    ->first();
                
                if ($order) {
                    $customerHash = $order->customer_hash;
                    $customer = $order->customer;
                }
            }

            // If no order found but customer_hash provided, find customer by hash
            if (!$customerHash && isset($validated['customer_hash'])) {
                $customerHash = $validated['customer_hash'];
                $customer = Customer::where('customer_hash', $customerHash)->first();
            }

            // Create or update voucher
            $voucher = Voucher::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'voucher_number' => $validated['voucher_number'],
                ],
                [
                    'order_id' => $order?->id,
                    'customer_id' => $customer?->id,
                    'customer_hash' => $customerHash,
                    'courier_name' => $validated['courier_name'] ?? null,
                    'courier_service' => $validated['courier_service'] ?? null,
                    'tracking_url' => $validated['tracking_url'] ?? null,
                    'status' => $validated['status'] ?? 'created',
                    'shipped_at' => isset($validated['shipped_at']) && !empty($validated['shipped_at'])
                        ? date('Y-m-d H:i:s', strtotime($validated['shipped_at']))
                        : null,
                    'delivered_at' => isset($validated['delivered_at']) && !empty($validated['delivered_at'])
                        ? date('Y-m-d H:i:s', strtotime($validated['delivered_at']))
                        : null,
                    'returned_at' => isset($validated['returned_at']) && !empty($validated['returned_at'])
                        ? date('Y-m-d H:i:s', strtotime($validated['returned_at']))
                        : null,
                    'failed_at' => isset($validated['failed_at']) && !empty($validated['failed_at'])
                        ? date('Y-m-d H:i:s', strtotime($validated['failed_at']))
                        : null,
                    'meta' => $validated['meta'] ?? null,
                ]
            );

            // Update customer stats if we have a customer hash
            if ($customerHash) {
                $this->statsService->updateStats($customerHash);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'voucher_id' => $voucher->id,
                'customer_hash' => $customerHash,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Voucher ingestion failed', [
                'error' => $e->getMessage(),
                'shop_id' => $shop->id,
                'data' => $validated,
            ]);

            return response()->json([
                'error' => 'Failed to process voucher',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get voucher details
     */
    public function show(Request $request, $id)
    {
        $shop = $request->attributes->get('shop');
        
        $voucher = Voucher::where('id', $id)
            ->where('shop_id', $shop->id)
            ->with(['customer', 'shop', 'order', 'courierEvents'])
            ->firstOrFail();

        return response()->json($voucher);
    }
}
