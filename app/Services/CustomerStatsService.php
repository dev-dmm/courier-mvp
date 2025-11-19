<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerStat;
use App\Models\Order;
use App\Models\Voucher;

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

        // Get aggregated stats from vouchers only (order status no longer used)
        $orderStats = $this->getOrderStats($customerHash);
        $voucherStats = $this->getVoucherStats($customerHash);

        // Get first and last order dates (using aggregations for efficiency)
        $firstOrderDate = Order::where('customer_hash', $customerHash)
            ->min('ordered_at');
        
        $lastOrderDate = Order::where('customer_hash', $customerHash)
            ->max('ordered_at');

        // Calculate risk score (only from vouchers: returns and late deliveries)
        $stats = CustomerStat::updateOrCreate(
            ['customer_hash' => $customerHash],
            [
                'customer_id' => $customer->id,
                'total_orders' => $orderStats['total_orders'],
                'late_deliveries' => $voucherStats['late_deliveries'],
                'returns' => $voucherStats['returns'],
                'first_order_at' => $firstOrderDate ? \Carbon\Carbon::parse($firstOrderDate) : null,
                'last_order_at' => $lastOrderDate ? \Carbon\Carbon::parse($lastOrderDate) : null,
            ]
        );

        // Calculate and update risk score
        $riskScore = $this->riskService->calculateRiskScore($stats);
        $stats->delivery_risk_score = $riskScore;
        $stats->save();

        return $stats->refresh();
    }

    /**
     * Get order statistics for a customer using database aggregations
     * 
     * Note: Only total_orders is used now. Order status is no longer tracked
     * as risk score is calculated only from vouchers (returns, late deliveries).
     * 
     * @param string $customerHash
     * @return array
     */
    private function getOrderStats(string $customerHash): array
    {
        $baseQuery = Order::where('customer_hash', $customerHash);

        return [
            'total_orders' => (int) $baseQuery->count(),
        ];
    }

    /**
     * Get voucher statistics for a customer using database aggregations
     * 
     * @param string $customerHash
     * @return array
     */
    private function getVoucherStats(string $customerHash): array
    {
        $baseQuery = Voucher::where('customer_hash', $customerHash);

        // Count returns
        $returns = (int) (clone $baseQuery)->where('status', 'returned')->count();

        // Count late deliveries: delivered more than 5 days after shipping
        // Using DATEDIFF (MySQL/MariaDB specific)
        // Note: For PostgreSQL, use: (delivered_at::date - shipped_at::date) > 5
        // For SQLite, use: julianday(delivered_at) - julianday(shipped_at) > 5
        $lateDeliveries = (int) (clone $baseQuery)
            ->where('status', 'delivered')
            ->whereNotNull('delivered_at')
            ->whereNotNull('shipped_at')
            ->whereRaw('DATEDIFF(delivered_at, shipped_at) > 5')
            ->count();

        return [
            'returns' => $returns,
            'late_deliveries' => $lateDeliveries,
        ];
    }
}

