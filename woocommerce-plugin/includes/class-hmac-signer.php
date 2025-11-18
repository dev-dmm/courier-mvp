<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HMAC Signer for API requests
 */
class Courier_Intelligence_HMAC_Signer {
    
    /**
     * Generate HMAC signature for request
     * 
     * @param string $timestamp
     * @param string $body
     * @param string $secret
     * @return string
     */
    public function sign($timestamp, $body, $secret) {
        $data_to_sign = $timestamp . '.' . $body;
        return hash_hmac('sha256', $data_to_sign, $secret);
    }
    
    /**
     * Get current timestamp
     * 
     * @return int
     */
    public function get_timestamp() {
        return time();
    }
}

