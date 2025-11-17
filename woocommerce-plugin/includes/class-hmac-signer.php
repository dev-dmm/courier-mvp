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
     * @param string $method
     * @param string $path
     * @param string $body
     * @param string $secret
     * @return string
     */
    public function sign($timestamp, $method, $path, $body, $secret) {
        $signature_string = $timestamp . $method . $path . $body;
        return hash_hmac('sha256', $signature_string, $secret);
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

