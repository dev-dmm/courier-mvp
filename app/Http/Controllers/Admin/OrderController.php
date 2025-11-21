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
            ->with(['shop', 'customer', 'vouchers.courierEvents']);

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

        // Vouchers are loaded via the relationship which uses order_id
        // This ensures each order only shows vouchers that are actually linked to it
        // No fallback to customer_hash - vouchers must be explicitly linked to the order

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['shop_id', 'customer_hash', 'status']),
        ]);
    }
}
