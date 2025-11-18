<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Order;
use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        
        // Get shops user has access to
        $shops = $user->shops()->get();
        
        // Get summary stats for user's shops
        $shopIds = $shops->pluck('id');
        
        $totalOrders = $shopIds->isNotEmpty() 
            ? Order::whereIn('shop_id', $shopIds)->count() 
            : 0;
            
        $totalCustomers = $shopIds->isNotEmpty()
            ? Customer::whereHas('orders', function ($query) use ($shopIds) {
                $query->whereIn('shop_id', $shopIds);
            })->count()
            : 0;
        
        return Inertia::render('Admin/Dashboard', [
            'shops' => $shops,
            'stats' => [
                'total_orders' => $totalOrders,
                'total_customers' => $totalCustomers,
            ],
        ]);
    }
}
