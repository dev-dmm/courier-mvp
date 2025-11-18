<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $shopIds = $user->shops()->select('shops.id')->pluck('id');
        
        $query = Order::whereIn('shop_id', $shopIds)
            ->with(['shop', 'customer', 'vouchers']);

        // Filter by shop
        if ($request->has('shop_id')) {
            $query->where('shop_id', $request->get('shop_id'));
        }

        // Filter by customer hash
        if ($request->has('customer_hash')) {
            $query->where('customer_hash', $request->get('customer_hash'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        $orders = $query->orderBy('ordered_at', 'desc')->paginate(20);

        // Load vouchers for all orders, including fallback lookup
        $orders->getCollection()->each(function ($order) use ($shopIds) {
            // First try the direct relationship
            if (!$order->relationLoaded('vouchers')) {
                $order->load('vouchers');
            }
            
            // If no vouchers found by order_id, try to find by customer_hash and shop
            // (vouchers might be linked by customer_hash even if order_id is null)
            if ($order->vouchers->isEmpty() && $order->customer_hash) {
                $fallbackVouchers = \App\Models\Voucher::whereIn('shop_id', $shopIds)
                    ->where('customer_hash', $order->customer_hash)
                    ->where('shop_id', $order->shop_id)
                    ->get();
                
                if ($fallbackVouchers->isNotEmpty()) {
                    $order->setRelation('vouchers', $fallbackVouchers);
                }
            }
        });

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['shop_id', 'customer_hash', 'status']),
        ]);
    }
}
