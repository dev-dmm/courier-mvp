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

        // Get first and last order dates (using aggregations for efficiency)
        $firstOrderDate = Order::where('customer_hash', $customerHash)
            ->min('ordered_at');
        
        $lastOrderDate = Order::where('customer_hash', $customerHash)
            ->max('ordered_at');

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
                'first_order_at' => $firstOrderDate ? \Carbon\Carbon::parse($firstOrderDate) : null,
                'last_order_at' => $lastOrderDate ? \Carbon\Carbon::parse($lastOrderDate) : null,
                'delivery_success_rate' => $successRate,
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
     * @param string $customerHash
     * @return array
     */
    private function getOrderStats(string $customerHash): array
    {
        $baseQuery = Order::where('customer_hash', $customerHash);

        return [
            'total_orders' => (int) $baseQuery->count(),
            'successful_deliveries' => (int) (clone $baseQuery)->where('status', 'completed')->count(),
            'failed_deliveries' => (int) (clone $baseQuery)
                ->whereIn('status', ['failed', 'cancelled', 'refunded'])
                ->count(),
            'cod_orders' => (int) (clone $baseQuery)->where('payment_method', 'cod')->count(),
            'cod_refusals' => (int) (clone $baseQuery)
                ->where('payment_method', 'cod')
                ->whereIn('status', ['failed', 'cancelled'])
                ->count(),
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

