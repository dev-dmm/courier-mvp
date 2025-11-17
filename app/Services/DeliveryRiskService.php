<?php

namespace App\Services;

use App\Models\CustomerStat;

class DeliveryRiskService
{
    /**
     * Calculate delivery risk score (0-100)
     * 
     * Formula:
     * (failed_deliveries * 30) + (cod_refusals * 40) + (returns * 20) + (late_deliveries * 10)
     * 
     * @param CustomerStat $stats
     * @return int
     */
    public function calculateRiskScore(CustomerStat $stats): int
    {
        $score = 0;
        
        $score += $stats->failed_deliveries * 30;
        $score += $stats->cod_refusals * 40;
        $score += $stats->returns * 20;
        $score += $stats->late_deliveries * 10;
        
        // Normalize to 0-100
        return min(100, max(0, $score));
    }

    /**
     * Calculate delivery success rate (0-100%)
     * 
     * @param CustomerStat $stats
     * @return float|null
     */
    public function calculateSuccessRate(CustomerStat $stats): ?float
    {
        if ($stats->total_orders === 0) {
            return null;
        }
        
        $successful = $stats->successful_deliveries;
        $total = $stats->total_orders;
        
        return round(($successful / $total) * 100, 2);
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

