<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Client for Courier Intelligence
 */
class Courier_Intelligence_API_Client {
    
    private $api_endpoint;
    private $api_key;
    private $api_secret;
    private $hmac_signer;
    
    public function __construct() {
        $settings = get_option('courier_intelligence_settings', array());
        $endpoint = $settings['api_endpoint'] ?? '';
        // Remove trailing slash and /api if present to avoid double /api/api/
        $endpoint = rtrim($endpoint, '/');
        $endpoint = preg_replace('#/api/?$#', '', $endpoint);
        $this->api_endpoint = $endpoint;
        $this->api_key = $settings['api_key'] ?? '';
        $this->api_secret = $settings['api_secret'] ?? '';
        $this->hmac_signer = new Courier_Intelligence_HMAC_Signer();
    }
    
    /**
     * Send order data to API
     * 
     * @param array $order_data
     * @param int|null $order_id Optional WooCommerce order ID for saving meta
     * @return bool|WP_Error
     */
    public function send_order($order_data, $order_id = null) {
        if (empty($this->api_endpoint) || empty($this->api_key) || empty($this->api_secret)) {
            $error = new WP_Error('missing_settings', 'API settings not configured');
            Courier_Intelligence_Logger::log('order', 'error', array(
                'external_order_id' => $order_data['external_order_id'] ?? null,
                'message' => 'API settings not configured',
                'error_code' => 'missing_settings',
                'error_message' => 'API settings not configured',
            ));
            return $error;
        }
        
        $path = '/api/orders';
        $url = $this->api_endpoint . $path;
        $body = json_encode($order_data);
        
        $timestamp = $this->hmac_signer->get_timestamp();
        $signature = $this->hmac_signer->sign($timestamp, $body, $this->api_secret);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-KEY' => $this->api_key,
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => $signature,
            ),
            'body' => $body,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Courier Intelligence: Failed to send order - ' . $error_message);
            Courier_Intelligence_Logger::log('order', 'error', array(
                'external_order_id' => $order_data['external_order_id'] ?? null,
                'message' => 'Failed to send order',
                'error_code' => $response->get_error_code(),
                'error_message' => $error_message,
                'url' => $url,
            ));
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code >= 200 && $status_code < 300) {
            // Parse response and save risk score to order meta
            $response_data = json_decode($response_body, true);
            
            if (!empty($response_data['success']) && !empty($response_data['risk_score']) && $order_id) {
                update_post_meta($order_id, '_oreksi_risk_score', intval($response_data['risk_score']));
                update_post_meta($order_id, '_oreksi_total_orders', intval($response_data['total_orders'] ?? 0));
                update_post_meta($order_id, '_oreksi_failed_deliveries', intval($response_data['failed_deliveries'] ?? 0));
                update_post_meta($order_id, '_oreksi_returns', intval($response_data['returns'] ?? 0));
            }
            
            // Prepare payload preview (first 500 chars) - this is already hashed, safe to log
            $payload_preview = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
            
            // Log success - only include non-PII fields and hashed data
            Courier_Intelligence_Logger::log('order', 'success', array(
                'external_order_id' => $order_data['external_order_id'] ?? null,
                'message' => 'Order sent successfully',
                'http_status' => $status_code,
                'url' => $url,
                'payload_preview' => $payload_preview, // Hashed data only, safe to log
                'response_body' => $response_body ? (strlen($response_body) > 500 ? substr($response_body, 0, 500) . '...' : $response_body) : null,
                // Key order fields for quick reference (non-PII only)
                'total_amount' => $order_data['total_amount'] ?? null,
                'currency' => $order_data['currency'] ?? null,
                'status' => $order_data['status'] ?? null,
                'risk_score' => $response_data['risk_score'] ?? null,
                // Note: customer_email is NOT logged - it's hashed in payload_preview as customer_hash
            ));
            return true;
        } else {
            error_log('Courier Intelligence: API error - ' . $status_code . ': ' . $response_body);
            Courier_Intelligence_Logger::log('order', 'error', array(
                'external_order_id' => $order_data['external_order_id'] ?? null,
                'message' => 'API request failed',
                'error_code' => 'api_error',
                'error_message' => 'HTTP ' . $status_code,
                'http_status' => $status_code,
                'response_body' => $response_body,
                'url' => $url,
            ));
            return new WP_Error('api_error', 'API request failed', array('status' => $status_code, 'body' => $response_body));
        }
    }
    
    /**
     * Send voucher data to API
     * 
     * @param array $voucher_data
     * @return bool|WP_Error
     */
    public function send_voucher($voucher_data) {
        if (empty($this->api_endpoint) || empty($this->api_key) || empty($this->api_secret)) {
            $error = new WP_Error('missing_settings', 'API settings not configured');
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $voucher_data['external_order_id'] ?? null,
                'message' => 'API settings not configured',
                'error_code' => 'missing_settings',
                'error_message' => 'API settings not configured',
            ));
            return $error;
        }
        
        $path = '/api/vouchers';
        $url = $this->api_endpoint . $path;
        $body = json_encode($voucher_data);
        
        // Debug: Log before sending
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'external_order_id' => $voucher_data['external_order_id'] ?? null,
            'message' => 'Sending voucher to API',
            'url' => $url,
            'payload_preview' => substr($body, 0, 200) . (strlen($body) > 200 ? '...' : ''),
        ));
        
        $timestamp = $this->hmac_signer->get_timestamp();
        $signature = $this->hmac_signer->sign($timestamp, $body, $this->api_secret);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-KEY' => $this->api_key,
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => $signature,
            ),
            'body' => $body,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Courier Intelligence: Failed to send voucher - ' . $error_message);
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $voucher_data['external_order_id'] ?? null,
                'message' => 'Failed to send voucher',
                'error_code' => $response->get_error_code(),
                'error_message' => $error_message,
                'url' => $url,
            ));
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code >= 200 && $status_code < 300) {
            // Prepare payload preview (first 500 chars)
            $payload_preview = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
            
            Courier_Intelligence_Logger::log('voucher', 'success', array(
                'external_order_id' => $voucher_data['external_order_id'] ?? null,
                'message' => 'Voucher sent successfully',
                'http_status' => $status_code,
                'url' => $url,
                'payload_preview' => $payload_preview,
                'response_body' => $response_body ? (strlen($response_body) > 500 ? substr($response_body, 0, 500) . '...' : $response_body) : null,
                // Key voucher fields for quick reference
                'voucher_number' => $voucher_data['voucher_number'] ?? null,
                'courier_name' => $voucher_data['courier_name'] ?? null,
                'status' => $voucher_data['status'] ?? null,
            ));
            return true;
        } else {
            error_log('Courier Intelligence: API error - ' . $status_code . ': ' . $response_body);
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $voucher_data['external_order_id'] ?? null,
                'message' => 'API request failed',
                'error_code' => 'api_error',
                'error_message' => 'HTTP ' . $status_code,
                'http_status' => $status_code,
                'response_body' => $response_body,
                'url' => $url,
            ));
            return new WP_Error('api_error', 'API request failed', array('status' => $status_code, 'body' => $response_body));
        }
    }
}

