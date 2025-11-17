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
        $this->api_endpoint = rtrim($settings['api_endpoint'] ?? '', '/');
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
            return new WP_Error('missing_settings', 'API settings not configured');
        }
        
        $path = '/api/orders';
        $url = $this->api_endpoint . $path;
        $body = json_encode($order_data);
        
        $timestamp = $this->hmac_signer->get_timestamp();
        $signature = $this->hmac_signer->sign($timestamp, 'POST', $path, $body, $this->api_secret);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->api_key,
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => $signature,
            ),
            'body' => $body,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            error_log('Courier Intelligence: Failed to send order - ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code >= 200 && $status_code < 300) {
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            error_log('Courier Intelligence: API error - ' . $status_code . ': ' . $body);
            return new WP_Error('api_error', 'API request failed', array('status' => $status_code, 'body' => $body));
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
            return new WP_Error('missing_settings', 'API settings not configured');
        }
        
        $path = '/api/vouchers';
        $url = $this->api_endpoint . $path;
        $body = json_encode($voucher_data);
        
        $timestamp = $this->hmac_signer->get_timestamp();
        $signature = $this->hmac_signer->sign($timestamp, 'POST', $path, $body, $this->api_secret);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->api_key,
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => $signature,
            ),
            'body' => $body,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            error_log('Courier Intelligence: Failed to send voucher - ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code >= 200 && $status_code < 300) {
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            error_log('Courier Intelligence: API error - ' . $status_code . ': ' . $body);
            return new WP_Error('api_error', 'API request failed', array('status' => $status_code, 'body' => $body));
        }
    }
}

