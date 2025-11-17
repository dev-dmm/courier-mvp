<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerStat;
use App\Models\Order;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

class CustomerStatsService
{
    public function __construct(
        private DeliveryRiskService $riskService
    ) {}

    /**
     * Update or create customer stats for a customer
     * 
     * @param string $customerHash
     * @return CustomerStat
     */
    public function updateStats(string $customerHash): CustomerStat
    {
        $customer = Customer::where('customer_hash', $customerHash)->first();
        
        if (!$customer) {
            throw new \Exception("Customer not found for hash: {$customerHash}");
        }

        // Get aggregated stats from orders and vouchers
        $orderStats = $this->getOrderStats($customerHash);
        $voucherStats = $this->getVoucherStats($customerHash);

        // Calculate success rate
        $successRate = null;
        if ($orderStats['total_orders'] > 0) {
            $successful = $orderStats['successful_deliveries'];
            $total = $orderStats['total_orders'];
            $successRate = round(($successful / $total) * 100, 2);
        }

        // Get first and last order dates
        $firstOrder = Order::where('customer_hash', $customerHash)
            ->orderBy('ordered_at', 'asc')
            ->first();
        
        $lastOrder = Order::where('customer_hash', $customerHash)
            ->orderBy('ordered_at', 'desc')
            ->first();

        // Calculate risk score
        $stats = CustomerStat::updateOrCreate(
            ['customer_hash' => $customerHash],
            [
                'customer_id' => $customer->id,
                'total_orders' => $orderStats['total_orders'],
                'successful_deliveries' => $orderStats['successful_deliveries'],
                'failed_deliveries' => $orderStats['failed_deliveries'],
                'late_deliveries' => $voucherStats['late_deliveries'],
                'returns' => $voucherStats['returns'],
                'cod_orders' => $orderStats['cod_orders'],
                'cod_refusals' => $orderStats['cod_refusals'],
                'first_order_at' => $firstOrder?->ordered_at,
                'last_order_at' => $lastOrder?->ordered_at,
                'delivery_success_rate' => $successRate,
            ]
        );

        // Calculate and update risk score
        $riskScore = $this->riskService->calculateRiskScore($stats);
        $stats->update(['delivery_risk_score' => $riskScore]);

        return $stats->fresh();
    }

    /**
     * Get order statistics for a customer
     * 
     * @param string $customerHash
     * @return array
     */
    private function getOrderStats(string $customerHash): array
    {
        $orders = Order::where('customer_hash', $customerHash)->get();

        $totalOrders = $orders->count();
        $successfulDeliveries = $orders->where('status', 'completed')->count();
        $failedDeliveries = $orders->whereIn('status', ['failed', 'cancelled', 'refunded'])->count();
        $codOrders = $orders->where('payment_method', 'cod')->count();
        $codRefusals = $orders->where('payment_method', 'cod')
            ->whereIn('status', ['failed', 'cancelled'])
            ->count();

        return [
            'total_orders' => $totalOrders,
            'successful_deliveries' => $successfulDeliveries,
            'failed_deliveries' => $failedDeliveries,
            'cod_orders' => $codOrders,
            'cod_refusals' => $codRefusals,
        ];
    }

    /**
     * Get voucher statistics for a customer
     * 
     * @param string $customerHash
     * @return array
     */
    private function getVoucherStats(string $customerHash): array
    {
        $vouchers = Voucher::where('customer_hash', $customerHash)->get();

        $returns = $vouchers->where('status', 'returned')->count();
        $lateDeliveries = $vouchers->where('status', 'delivered')
            ->whereNotNull('delivered_at')
            ->filter(function ($voucher) {
                // Check if delivery was late (more than expected days)
                // This is a simplified check - you can enhance it
                return $voucher->delivered_at && $voucher->shipped_at &&
                    $voucher->delivered_at->diffInDays($voucher->shipped_at) > 5;
            })
            ->count();

        return [
            'returns' => $returns,
            'late_deliveries' => $lateDeliveries,
        ];
    }
}

