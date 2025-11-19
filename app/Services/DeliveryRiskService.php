<?php

namespace App\Services;

use App\Models\CustomerStat;

class DeliveryRiskService
{
    /**
     * Calculate delivery risk score (0-100)
     * 
     * Formula (based on vouchers only - returns and late deliveries):
     * - Returns penalty: (returns / total_orders) * 50 (if >= 5 orders) or returns * 15 (if < 5 orders)
     * - Late delivery penalty: (late_deliveries / total_orders) * 50 (if >= 5 orders) or late_deliveries * 5 (if < 5 orders)
     * 
     * Note: Order status (failed_deliveries, cod_refusals) is no longer used
     * as it's not sent from WooCommerce. Risk score is calculated only from
     * voucher data (returns and late deliveries).
     * 
     * @param CustomerStat $stats
     * @return int
     */
    public function calculateRiskScore(CustomerStat $stats): int
    {
        $score = 0;
        
        // Only use voucher-based metrics (returns and late deliveries)
        if ($stats->total_orders >= 5) {
            // Percentage-based (more fair for customers with many orders)
            $returnRate = $stats->total_orders > 0 
                ? ($stats->returns / $stats->total_orders) * 50 
                : 0;
            $lateDeliveryRate = $stats->total_orders > 0 
                ? ($stats->late_deliveries / $stats->total_orders) * 50 
                : 0;
            
            $score = $returnRate + $lateDeliveryRate;
        } else {
            // For new customers (fewer than 5 orders), use absolute counts
            $score += $stats->returns * 15;
            $score += $stats->late_deliveries * 5;
        }
        
        // Normalize to 0-100
        return min(100, max(0, (int) round($score)));
    }

    /**
     * Get risk level color code
     * 
     * @param int $riskScore
     * @return string 'green'|'yellow'|'red'
     */
    public function getRiskLevel(int $riskScore): string
    {
        if ($riskScore <= 30) {
            return 'green';
        } elseif ($riskScore <= 60) {
            return 'yellow';
        } else {
            return 'red';
        }
    }
}

