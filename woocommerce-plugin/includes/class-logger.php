<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger for Courier Intelligence plugin
 */
class Courier_Intelligence_Logger {
    
    const LOG_OPTION_NAME = 'courier_intelligence_logs';
    const MAX_LOGS = 500; // Keep last 500 logs
    
    /**
     * Log an event
     * 
     * @param string $type 'order' or 'voucher'
     * @param string $status 'success' or 'error'
     * @param array $data Log data
     * @return void
     */
    public static function log($type, $status, $data = array()) {
        $logs = self::get_logs();
        
        $log_entry = array(
            'id' => uniqid('log_', true),
            'timestamp' => current_time('mysql'),
            'type' => $type, // 'order' or 'voucher'
            'status' => $status, // 'success', 'error', or 'debug'
            'order_id' => $data['order_id'] ?? null,
            'external_order_id' => $data['external_order_id'] ?? null,
            'message' => $data['message'] ?? '',
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'http_status' => $data['http_status'] ?? null,
            'response_body' => $data['response_body'] ?? null,
            'url' => $data['url'] ?? null,
            // Debug fields
            'meta_key' => $data['meta_key'] ?? $data['meta_key_used'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'payload_preview' => $data['payload_preview'] ?? null,
        );
        
        // Add to beginning of array (newest first)
        array_unshift($logs, $log_entry);
        
        // Keep only last MAX_LOGS entries
        $logs = array_slice($logs, 0, self::MAX_LOGS);
        
        update_option(self::LOG_OPTION_NAME, $logs, false);
    }
    
    /**
     * Get all logs
     * 
     * @param array $filters Optional filters
     * @return array
     */
    public static function get_logs($filters = array()) {
        $logs = get_option(self::LOG_OPTION_NAME, array());
        
        if (empty($logs)) {
            return array();
        }
        
        // Apply filters
        if (!empty($filters['type'])) {
            $logs = array_filter($logs, function($log) use ($filters) {
                return $log['type'] === $filters['type'];
            });
        }
        
        if (!empty($filters['status'])) {
            $logs = array_filter($logs, function($log) use ($filters) {
                return $log['status'] === $filters['status'];
            });
        }
        
        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $logs = array_filter($logs, function($log) use ($search) {
                return (
                    stripos($log['external_order_id'] ?? '', $search) !== false ||
                    stripos($log['message'] ?? '', $search) !== false ||
                    stripos($log['error_message'] ?? '', $search) !== false
                );
            });
        }
        
        // Re-index array after filtering
        $logs = array_values($logs);
        
        return $logs;
    }
    
    /**
     * Get paginated logs
     * 
     * @param int $page Page number (1-based)
     * @param int $per_page Items per page
     * @param array $filters Optional filters
     * @return array ['logs' => array, 'total' => int, 'pages' => int]
     */
    public static function get_paginated_logs($page = 1, $per_page = 50, $filters = array()) {
        $all_logs = self::get_logs($filters);
        $total = count($all_logs);
        $pages = ceil($total / $per_page);
        
        $offset = ($page - 1) * $per_page;
        $logs = array_slice($all_logs, $offset, $per_page);
        
        return array(
            'logs' => $logs,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'per_page' => $per_page,
        );
    }
    
    /**
     * Clear all logs
     * 
     * @return void
     */
    public static function clear_logs() {
        delete_option(self::LOG_OPTION_NAME);
    }
    
    /**
     * Get log statistics
     * 
     * @return array
     */
    public static function get_stats() {
        $logs = self::get_logs();
        
        $stats = array(
            'total' => count($logs),
            'orders_success' => 0,
            'orders_error' => 0,
            'vouchers_success' => 0,
            'vouchers_error' => 0,
            'vouchers_debug' => 0,
            'last_24h' => 0,
            'last_7d' => 0,
        );
        
        $now = current_time('timestamp');
        $day_ago = $now - DAY_IN_SECONDS;
        $week_ago = $now - WEEK_IN_SECONDS;
        
        foreach ($logs as $log) {
            $log_time = strtotime($log['timestamp']);
            
            if ($log_time >= $day_ago) {
                $stats['last_24h']++;
            }
            if ($log_time >= $week_ago) {
                $stats['last_7d']++;
            }
            
            if ($log['type'] === 'order') {
                if ($log['status'] === 'success') {
                    $stats['orders_success']++;
                } else {
                    $stats['orders_error']++;
                }
            } elseif ($log['type'] === 'voucher') {
                if ($log['status'] === 'success') {
                    $stats['vouchers_success']++;
                } elseif ($log['status'] === 'debug') {
                    $stats['vouchers_debug']++;
                } else {
                    $stats['vouchers_error']++;
                }
            }
        }
        
        return $stats;
    }
}

