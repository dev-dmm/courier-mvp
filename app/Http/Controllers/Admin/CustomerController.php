<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerStat;
use App\Models\Order;
use App\Services\DeliveryRiskService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(
        private DeliveryRiskService $riskService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $shopIds = $user->shops()->select('shops.id')->pluck('id');
        
        $query = Customer::whereHas('orders', function ($q) use ($shopIds) {
            $q->whereIn('shop_id', $shopIds);
        })->with('stats');

        // Search by email hash if provided
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where('customer_hash', 'like', "%{$search}%")
                ->orWhere('primary_email', 'like', "%{$search}%");
        }

        $customers = $query->paginate(20);

        return Inertia::render('Admin/Customers/Index', [
            'customers' => $customers,
        ]);
    }

    public function show(Request $request, string $hash): Response
    {
        $user = $request->user();
        $shopIds = $user->shops()->select('shops.id')->pluck('id');
        
        $customer = Customer::where('customer_hash', $hash)
            ->with('stats')
            ->firstOrFail();

        // Get orders from user's shops
        $orders = Order::where('customer_hash', $hash)
            ->whereIn('shop_id', $shopIds)
            ->with(['shop', 'vouchers'])
            ->orderBy('ordered_at', 'desc')
            ->paginate(20);

        $riskLevel = $customer->stats 
            ? $this->riskService->getRiskLevel($customer->stats->delivery_risk_score)
            : 'green';

        return Inertia::render('Admin/Customers/Show', [
            'customer' => $customer,
            'orders' => $orders,
            'riskLevel' => $riskLevel,
        ]);
    }
}
