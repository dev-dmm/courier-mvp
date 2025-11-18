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
     * @return bool|WP_Error
     */
    public function send_order($order_data) {
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
            Courier_Intelligence_Logger::log('order', 'success', array(
                'external_order_id' => $order_data['external_order_id'] ?? null,
                'message' => 'Order sent successfully',
                'http_status' => $status_code,
                'url' => $url,
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
            Courier_Intelligence_Logger::log('voucher', 'success', array(
                'external_order_id' => $voucher_data['external_order_id'] ?? null,
                'message' => 'Voucher sent successfully',
                'http_status' => $status_code,
                'url' => $url,
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

