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
            
            // Save risk score if present in response (0 is a valid score, so check isset instead of !empty)
            if (!empty($response_data['success']) && isset($response_data['risk_score']) && $order_id) {
                // Use order object methods to support both traditional and HPOS orders
                $order = wc_get_order($order_id);
                if ($order) {
                    $risk_score = intval($response_data['risk_score']);
                    $order->update_meta_data('_oreksi_risk_score', $risk_score);
                    $order->save();
                    
                    // Log that we saved the risk score
                    Courier_Intelligence_Logger::log('order', 'debug', array(
                        'external_order_id' => $order_data['external_order_id'] ?? null,
                        'message' => 'Risk score saved to order meta',
                        'risk_score' => $risk_score,
                        'order_id' => $order_id,
                    ));
                } else {
                    Courier_Intelligence_Logger::log('order', 'error', array(
                        'external_order_id' => $order_data['external_order_id'] ?? null,
                        'message' => 'Failed to get order object to save risk score',
                        'order_id' => $order_id,
                    ));
                }
            } elseif ($order_id && (!isset($response_data['risk_score']) || empty($response_data['success']))) {
                // Log if risk score is missing from response
                Courier_Intelligence_Logger::log('order', 'debug', array(
                    'external_order_id' => $order_data['external_order_id'] ?? null,
                    'message' => 'Risk score not found in API response or success is false',
                    'response_data' => $response_data,
                ));
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
     * @param string|null $api_key Optional API key to override instance key
     * @param string|null $api_secret Optional API secret to override instance secret
     * @return bool|WP_Error
     */
    public function send_voucher($voucher_data, $api_key = null, $api_secret = null) {
        // Use provided credentials or fall back to instance credentials
        $api_key = $api_key ?? $this->api_key;
        $api_secret = $api_secret ?? $this->api_secret;
        
        if (empty($this->api_endpoint) || empty($api_key) || empty($api_secret)) {
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
        $signature = $this->hmac_signer->sign($timestamp, $body, $api_secret);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-KEY' => $api_key,
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
    
    /**
     * Send voucher status update to API
     * 
     * This sends actual tracking status from courier APIs (delivered, in_transit, etc.)
     * as opposed to send_voucher() which only sends the voucher number.
     * 
     * Uses the /api/vouchers endpoint which supports updates via updateOrCreate.
     * 
     * @param array $status_data Status update data
     * @param string|null $api_key Optional API key to override instance key
     * @param string|null $api_secret Optional API secret to override instance secret
     * @return bool|WP_Error
     */
    public function send_voucher_status_update($status_data, $api_key = null, $api_secret = null) {
        // Use provided credentials or fall back to instance credentials
        $api_key = $api_key ?? $this->api_key;
        $api_secret = $api_secret ?? $this->api_secret;
        
        if (empty($this->api_endpoint) || empty($api_key) || empty($api_secret)) {
            $error = new WP_Error('missing_settings', 'API settings not configured');
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $status_data['external_order_id'] ?? null,
                'message' => 'API settings not configured for status update',
                'error_code' => 'missing_settings',
                'error_message' => 'API settings not configured',
            ));
            return $error;
        }
        
        // Map status update data to API format
        // The API expects: voucher_number, external_order_id, customer_hash, courier_name,
        // status, delivered_at, returned_at, failed_at, shipped_at
        // Map courier status to API status format
        $raw_status = strtolower($status_data['status'] ?? 'created');
        // Map 'issue' (from Elta) to 'failed' (API format)
        // Map 'unknown' to 'created' (safer default)
        $status_map = array(
            'issue' => 'failed',
            'unknown' => 'created',
        );
        $mapped_status = $status_map[$raw_status] ?? $raw_status;
        
        // Ensure status is one of the valid API values
        $valid_statuses = array('created', 'shipped', 'in_transit', 'delivered', 'returned', 'failed');
        if (!in_array($mapped_status, $valid_statuses, true)) {
            $mapped_status = 'created'; // Safe default
        }
        
        $voucher_data = array(
            'voucher_number' => $status_data['voucher_number'] ?? '',
            'external_order_id' => $status_data['external_order_id'] ?? null,
            'customer_hash' => $status_data['customer_hash'] ?? null,
            'courier_name' => $status_data['courier_name'] ?? null,
            'status' => $mapped_status,
        );
        
        // Map delivery date/time
        if (!empty($status_data['delivered']) && !empty($status_data['delivery_date'])) {
            $delivery_datetime = $status_data['delivery_date'];
            if (!empty($status_data['delivery_time'])) {
                $delivery_datetime .= ' ' . $status_data['delivery_time'];
            }
            $voucher_data['delivered_at'] = $delivery_datetime;
        }
        
        // Map returned date
        if (!empty($status_data['returned']) && !empty($status_data['delivery_date'])) {
            $voucher_data['returned_at'] = $status_data['delivery_date'];
        }
        
        // Map status to appropriate date fields (use mapped status)
        $status = $mapped_status;
        if ($status === 'delivered' && !empty($status_data['delivery_date'])) {
            $delivery_datetime = $status_data['delivery_date'];
            if (!empty($status_data['delivery_time'])) {
                $delivery_datetime .= ' ' . $status_data['delivery_time'];
            }
            $voucher_data['delivered_at'] = $delivery_datetime;
        } elseif ($status === 'returned' && !empty($status_data['delivery_date'])) {
            $voucher_data['returned_at'] = $status_data['delivery_date'];
        } elseif ($status === 'failed' && !empty($status_data['delivery_date'])) {
            $voucher_data['failed_at'] = $status_data['delivery_date'];
        }
        
        // Use the /api/vouchers endpoint (same as send_voucher, but with status update data)
        $path = '/api/vouchers';
        $url = $this->api_endpoint . $path;
        $body = json_encode($voucher_data);
        
        // Debug: Log before sending
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'external_order_id' => $status_data['external_order_id'] ?? null,
            'message' => 'Sending voucher status update to API',
            'url' => $url,
            'voucher_number' => $status_data['voucher_number'] ?? null,
            'status' => $status_data['status'] ?? null,
            'courier_name' => $status_data['courier_name'] ?? null,
            'payload_preview' => substr($body, 0, 200) . (strlen($body) > 200 ? '...' : ''),
        ));
        
        $timestamp = $this->hmac_signer->get_timestamp();
        $signature = $this->hmac_signer->sign($timestamp, $body, $api_secret);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-KEY' => $api_key,
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => $signature,
            ),
            'body' => $body,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Courier Intelligence: Failed to send voucher status update - ' . $error_message);
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $status_data['external_order_id'] ?? null,
                'message' => 'Failed to send voucher status update',
                'error_code' => $response->get_error_code(),
                'error_message' => $error_message,
                'url' => $url,
                'voucher_number' => $status_data['voucher_number'] ?? null,
            ));
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code >= 200 && $status_code < 300) {
            Courier_Intelligence_Logger::log('voucher', 'success', array(
                'external_order_id' => $status_data['external_order_id'] ?? null,
                'message' => 'Voucher status update sent successfully',
                'http_status' => $status_code,
                'url' => $url,
                'voucher_number' => $status_data['voucher_number'] ?? null,
                'status' => $status_data['status'] ?? null,
                'courier_name' => $status_data['courier_name'] ?? null,
                'delivered' => $status_data['delivered'] ?? false,
            ));
            return true;
        } else {
            error_log('Courier Intelligence: API error - ' . $status_code . ': ' . $response_body);
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $status_data['external_order_id'] ?? null,
                'message' => 'API request failed for status update',
                'error_code' => 'api_error',
                'error_message' => 'HTTP ' . $status_code,
                'http_status' => $status_code,
                'response_body' => $response_body,
                'url' => $url,
                'voucher_number' => $status_data['voucher_number'] ?? null,
            ));
            return new WP_Error('api_error', 'API request failed', array('status' => $status_code, 'body' => $response_body));
        }
    }
    
    /**
     * Delete voucher from API
     * 
     * @param string $voucher_number Voucher number to delete
     * @param string|null $external_order_id Optional order ID for logging
     * @param string|null $api_key Optional API key to override instance key
     * @param string|null $api_secret Optional API secret to override instance secret
     * @return bool|WP_Error
     */
    public function delete_voucher($voucher_number, $external_order_id = null, $api_key = null, $api_secret = null) {
        // Use provided credentials or fall back to instance credentials
        $api_key = $api_key ?? $this->api_key;
        $api_secret = $api_secret ?? $this->api_secret;
        
        if (empty($this->api_endpoint) || empty($api_key) || empty($api_secret)) {
            $error = new WP_Error('missing_settings', 'API settings not configured');
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $external_order_id,
                'message' => 'API settings not configured for voucher deletion',
                'error_code' => 'missing_settings',
                'error_message' => 'API settings not configured',
                'voucher_number' => $voucher_number,
            ));
            return $error;
        }
        
        // Use DELETE endpoint with voucher number in URL
        $path = '/api/vouchers/' . urlencode($voucher_number);
        $url = $this->api_endpoint . $path;
        
        // Debug: Log before sending
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'external_order_id' => $external_order_id,
            'message' => 'Sending voucher deletion request to API',
            'url' => $url,
            'voucher_number' => $voucher_number,
        ));
        
        $timestamp = $this->hmac_signer->get_timestamp();
        // For DELETE, body can be empty or contain voucher_number for HMAC signing
        $body = json_encode(array('voucher_number' => $voucher_number));
        $signature = $this->hmac_signer->sign($timestamp, $body, $api_secret);
        
        // Try DELETE method first
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-KEY' => $api_key,
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => $signature,
            ),
            'body' => $body,
            'timeout' => 30,
        ));
        
        // If DELETE method not allowed (405), try POST with delete flag as fallback
        if (!is_wp_error($response)) {
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code === 405) {
                // Method not allowed, try POST with X-Action header
                $response = wp_remote_post($url, array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'X-API-KEY' => $api_key,
                        'X-Timestamp' => (string) $timestamp,
                        'X-Signature' => $signature,
                        'X-Action' => 'delete',
                    ),
                    'body' => $body,
                    'timeout' => 30,
                ));
            }
        }
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('Courier Intelligence: Failed to delete voucher - ' . $error_message);
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $external_order_id,
                'message' => 'Failed to delete voucher',
                'error_code' => $response->get_error_code(),
                'error_message' => $error_message,
                'url' => $url,
                'voucher_number' => $voucher_number,
            ));
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($status_code >= 200 && $status_code < 300) {
            Courier_Intelligence_Logger::log('voucher', 'success', array(
                'external_order_id' => $external_order_id,
                'message' => 'Voucher deleted successfully',
                'http_status' => $status_code,
                'url' => $url,
                'voucher_number' => $voucher_number,
            ));
            return true;
        } else {
            error_log('Courier Intelligence: API error deleting voucher - ' . $status_code . ': ' . $response_body);
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $external_order_id,
                'message' => 'API request failed for voucher deletion',
                'error_code' => 'api_error',
                'error_message' => 'HTTP ' . $status_code,
                'http_status' => $status_code,
                'response_body' => $response_body,
                'url' => $url,
                'voucher_number' => $voucher_number,
            ));
            return new WP_Error('api_error', 'API request failed', array('status' => $status_code, 'body' => $response_body));
        }
    }
}

