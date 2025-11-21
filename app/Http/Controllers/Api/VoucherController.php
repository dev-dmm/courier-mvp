<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\Order;
use App\Models\Customer;
use App\Models\CourierEvent;
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
            'events' => 'nullable|array', // Tracking events from courier
            'events.*.date' => 'nullable|string',
            'events.*.time' => 'nullable|string',
            'events.*.station' => 'nullable|string',
            'events.*.status_title' => 'nullable|string',
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

            // Process and store courier events if provided
            if (isset($validated['events']) && is_array($validated['events']) && !empty($validated['events'])) {
                foreach ($validated['events'] as $eventData) {
                    if (empty($eventData['status_title']) && empty($eventData['station'])) {
                        continue; // Skip empty events
                    }
                    
                    // Parse event date/time
                    $eventDate = $eventData['date'] ?? null;
                    $eventTime = $eventData['time'] ?? null;
                    $eventDateTime = null;
                    
                    if ($eventDate) {
                        // Try to parse date (format: YYYYMMDD or YYYY-MM-DD)
                        $dateStr = $eventDate;
                        if ($eventTime) {
                            // Try to parse time (format: HHMM or HH:MM)
                            $timeStr = strlen($eventTime) === 4 ? substr($eventTime, 0, 2) . ':' . substr($eventTime, 2, 2) : $eventTime;
                            $dateStr .= ' ' . $timeStr;
                        }
                        
                        // Try multiple date formats
                        $parsed = \Carbon\Carbon::createFromFormat('Ymd H:i', $dateStr) 
                            ?: \Carbon\Carbon::createFromFormat('Y-m-d H:i', $dateStr)
                            ?: \Carbon\Carbon::createFromFormat('Ymd', $eventDate);
                        
                        if ($parsed) {
                            $eventDateTime = $parsed->format('Y-m-d H:i:s');
                        }
                    }
                    
                    // Create or update courier event
                    CourierEvent::updateOrCreate(
                        [
                            'voucher_id' => $voucher->id,
                            'event_time' => $eventDateTime,
                            'event_description' => $eventData['status_title'] ?? '',
                        ],
                        [
                            'courier_name' => $validated['courier_name'] ?? null,
                            'location' => $eventData['station'] ?? null,
                            'event_code' => null, // Can be extracted from status_title if needed
                            'raw_payload' => $eventData,
                        ]
                    );
                }
            }

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

    /**
     * Delete voucher by voucher number
     */
    public function destroy(Request $request, $voucher_number)
    {
        $shop = $request->attributes->get('shop');
        
        // Handle both DELETE method and POST with X-Action: delete header
        $voucher_number = urldecode($voucher_number);
        
        // If request body contains voucher_number, use that (for POST fallback)
        if ($request->has('voucher_number')) {
            $voucher_number = $request->input('voucher_number');
        }
        
        $voucher = Voucher::where('shop_id', $shop->id)
            ->where('voucher_number', $voucher_number)
            ->first();
        
        if (!$voucher) {
            return response()->json([
                'error' => 'Voucher not found',
                'message' => 'Voucher with number ' . $voucher_number . ' not found',
            ], 404);
        }
        
        try {
            DB::beginTransaction();
            
            // Delete associated courier events first (if cascade doesn't handle it)
            $voucher->courierEvents()->delete();
            
            // Delete the voucher
            $voucher->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Voucher deleted successfully',
                'voucher_number' => $voucher_number,
            ], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Voucher deletion failed', [
                'error' => $e->getMessage(),
                'shop_id' => $shop->id,
                'voucher_number' => $voucher_number,
            ]);
            
            return response()->json([
                'error' => 'Failed to delete voucher',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
