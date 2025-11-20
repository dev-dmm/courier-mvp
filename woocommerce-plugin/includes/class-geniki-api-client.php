<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Geniki Taxidromiki Tracking API Client
 * 
 * Minimal implementation for tracking existing vouchers only.
 * 
 * IMPORTANT: This class uses SOAP/WSDL web service.
 * 
 * Geniki API Specifications:
 * - Base URL: https://voucher.taxydromiki.gr/JobServicesV2.asmx
 * - Test URL: https://testvoucher.taxydromiki.gr/JobServicesV2.asmx
 * - WSDL: Available at {base_url}?WSDL (publicly accessible)
 * - Authentication: Required via Authenticate method (returns auth key)
 * - Tracking: TrackAndTrace method returns checkpoints and status
 * 
 * Required Credentials:
 * - Username (sUsrName)
 * - Password (sUsrPwd)
 * - Application Key (applicationKey)
 * 
 * Usage:
 *   $client = new Courier_Intelligence_Geniki_API_Client();
 *   $result = $client->get_voucher_status('1234567890');
 * 
 * This class only READS tracking information for existing vouchers.
 * It does NOT create vouchers (vouchers come from order meta keys via other plugins).
 */
class Courier_Intelligence_Geniki_API_Client {
    
    private $wsdl_url;
    private $username;
    private $password;
    private $application_key;
    private $test_mode;
    private $auth_key;  // Cached authentication key
    private $auth_key_expires;  // When the auth key expires
    
    /**
     * Constructor
     */
    public function __construct($courier_settings = array()) {
        // Get Geniki-specific settings
        $settings = get_option('courier_intelligence_settings', array());
        // Try both 'geniki_taxidromiki' and 'geniki' keys for compatibility
        $geniki_settings = $settings['couriers']['geniki_taxidromiki'] ?? $settings['couriers']['geniki'] ?? array();
        
        // Merge with provided settings
        $geniki_settings = array_merge($geniki_settings, $courier_settings);
        
        // Test mode flag
        $this->test_mode = isset($geniki_settings['test_mode']) && $geniki_settings['test_mode'] === 'yes';
        
        // Set WSDL URL - ensure ?WSDL is appended if not present
        if ($this->test_mode) {
            $endpoint = $geniki_settings['test_endpoint'] ?? 'https://testvoucher.taxydromiki.gr/JobServicesV2.asmx';
        } else {
            $endpoint = $geniki_settings['api_endpoint'] ?? 'https://voucher.taxydromiki.gr/JobServicesV2.asmx';
        }
        
        // Ensure ?WSDL is appended to the endpoint URL
        if (strpos($endpoint, '?WSDL') === false && strpos($endpoint, '?wsdl') === false) {
            $this->wsdl_url = rtrim($endpoint, '/') . '?WSDL';
        } else {
            $this->wsdl_url = $endpoint;
        }
        
        // Geniki authentication credentials
        $this->username = $geniki_settings['username'] ?? $geniki_settings['s_usr_name'] ?? '';
        $this->password = $geniki_settings['password'] ?? $geniki_settings['s_usr_pwd'] ?? '';
        $this->application_key = $geniki_settings['application_key'] ?? $geniki_settings['app_key'] ?? '';
        
        // Initialize auth key cache
        $this->auth_key = null;
        $this->auth_key_expires = null;
    }
    
    /**
     * Get voucher status and delivery information
     * 
     * This is the main method you'll use. It takes a voucher number and returns
     * all available tracking information including status, events, and delivery details.
     * 
     * @param string $voucher_code Voucher number
     * @return array|WP_Error Status and delivery information
     * 
     * Example response:
     *   array(
     *     'success' => true,
     *     'delivered' => true,
     *     'status' => 'delivered',
     *     'status_title' => 'Shipment Delivered',
     *     'delivery_date' => 'YYYY-MM-DD',
     *     'delivery_time' => 'HH:MM',
     *     'recipient_name' => 'Name',
     *     'events' => array(...),
     *     'raw_response' => array(...)
     *   )
     */
    public function get_voucher_status($voucher_code) {
        return $this->track_shipment($voucher_code);
    }
    
    /**
     * Track a shipment
     * 
     * Uses TrackAndTrace method to get complete tracking information.
     * 
     * @param string $voucher_code Voucher number
     * @param string $language Language code ('el' for Greek, 'en' for English, default: 'en')
     * @return array|WP_Error Tracking data or error
     */
    public function track_shipment($voucher_code, $language = 'en') {
        if (empty($this->username) || empty($this->password) || empty($this->application_key)) {
            return new WP_Error('missing_credentials', 'Geniki API credentials not configured');
        }
        
        // Authenticate first (or use cached key)
        $auth_key = $this->authenticate();
        if (is_wp_error($auth_key)) {
            return $auth_key;
        }
        
        // Make TrackAndTrace request
        $response = $this->make_track_and_trace_request($auth_key, $voucher_code, $language);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $this->parse_tracking_response($response);
    }
    
    /**
     * Validate WSDL URL accessibility
     * 
     * Checks if the WSDL URL is accessible and returns valid WSDL (not HTML).
     * 
     * @param string $wsdl_url WSDL URL to validate
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    private function validate_wsdl_url($wsdl_url) {
        if (empty($wsdl_url)) {
            return new WP_Error('empty_wsdl_url', 'WSDL URL is empty. Please configure the Geniki API endpoint in settings.');
        }
        
        // Check if URL is valid
        if (!filter_var($wsdl_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_wsdl_url', sprintf('Invalid WSDL URL: %s', $wsdl_url));
        }
        
        // Try to fetch the WSDL to check if it's accessible
        $response = wp_remote_get($wsdl_url, array(
            'timeout' => 15,
            'sslverify' => true,
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error(
                'wsdl_unreachable',
                sprintf('Cannot reach WSDL URL %s. Error: %s', $wsdl_url, $response->get_error_message()),
                array('wsdl_url' => $wsdl_url)
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error(
                'wsdl_http_error',
                sprintf('WSDL URL returned HTTP %d. Please verify the endpoint URL is correct.', $response_code),
                array('wsdl_url' => $wsdl_url, 'http_code' => $response_code)
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Check if response is HTML (common error when WSDL is not found)
        if (stripos($body, '<html') !== false || stripos($body, '<!DOCTYPE') !== false) {
            return new WP_Error(
                'wsdl_html_response',
                sprintf('WSDL URL returned HTML instead of WSDL. This usually means the endpoint URL is incorrect or the ?WSDL parameter is missing. URL: %s', $wsdl_url),
                array('wsdl_url' => $wsdl_url)
            );
        }
        
        // Check if response contains WSDL-like content
        if (stripos($body, 'wsdl:') === false && stripos($body, 'definitions') === false) {
            return new WP_Error(
                'wsdl_invalid_format',
                sprintf('WSDL URL does not appear to return valid WSDL content. Please verify the endpoint URL includes ?WSDL. URL: %s', $wsdl_url),
                array('wsdl_url' => $wsdl_url)
            );
        }
        
        return true;
    }
    
    /**
     * Authenticate with Geniki API
     * 
     * Returns authentication key that is used for subsequent API calls.
     * The key is cached to avoid unnecessary authentication calls.
     * 
     * @return string|WP_Error Authentication key or error
     */
    private function authenticate() {
        // Check if we have a valid cached auth key
        if ($this->auth_key !== null && $this->auth_key_expires !== null) {
            if (time() < $this->auth_key_expires) {
                return $this->auth_key;
            }
        }
        
        // Check if SOAP extension is available
        if (!class_exists('SoapClient')) {
            return new WP_Error('soap_not_available', 'SOAP extension is not available. Please enable PHP SOAP extension.');
        }
        
        // Validate WSDL URL before attempting to connect
        $wsdl_validation = $this->validate_wsdl_url($this->wsdl_url);
        if (is_wp_error($wsdl_validation)) {
            return $wsdl_validation;
        }
        
        try {
            // Create SOAP client with improved options
            $soap_options = array(
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'encoding' => 'UTF-8',
                'connection_timeout' => 15,
                'stream_context' => stream_context_create(array(
                    'http' => array(
                        'timeout' => 15,
                        'user_agent' => 'WordPress/CourierIntelligence',
                    ),
                )),
            );
            
            $client = new SoapClient($this->wsdl_url, $soap_options);
            
            // Call Authenticate method
            $result = $client->Authenticate(array(
                'sUsrName' => $this->username,
                'sUsrPwd' => $this->password,
                'applicationKey' => $this->application_key,
            ));
            
            // Convert SOAP result to array
            $response = json_decode(json_encode($result), true);
            
            // Extract AuthenticateResult
            $auth_result = $response['AuthenticateResult'] ?? $response;
            
            // Check result code (0 = success)
            $result_code = isset($auth_result['Result']) ? intval($auth_result['Result']) : -1;
            
            if ($result_code !== 0) {
                $error_message = 'Authentication failed';
                if ($result_code === 1) {
                    $error_message = 'Authentication failed: Invalid username, password, or application key';
                }
                
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'message' => 'Geniki authentication failed',
                    'result_code' => $result_code,
                    'courier' => 'Geniki',
                ));
                
                return new WP_Error('geniki_auth_failed', $error_message, array(
                    'result_code' => $result_code,
                ));
            }
            
            // Get authentication key
            $auth_key = $auth_result['Key'] ?? '';
            
            if (empty($auth_key)) {
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'message' => 'Geniki authentication returned empty key',
                    'courier' => 'Geniki',
                ));
                
                return new WP_Error('geniki_auth_empty_key', 'Authentication succeeded but no key was returned');
            }
            
            // Cache the auth key (assume it's valid for 1 hour, though API doesn't specify)
            // If we get error 11 (Invalid key) later, we'll re-authenticate
            $this->auth_key = $auth_key;
            $this->auth_key_expires = time() + 3600; // 1 hour
            
            return $auth_key;
            
        } catch (SoapFault $e) {
            $error_message = $e->getMessage();
            
            // Provide more helpful error messages for common WSDL issues
            if (strpos($error_message, 'Parsing WSDL') !== false || strpos($error_message, 'Couldn\'t load') !== false) {
                $error_message = sprintf(
                    'Failed to load WSDL from %s. Please verify: 1) The endpoint URL is correct and includes ?WSDL, 2) The server is accessible, 3) The server is returning valid WSDL (not HTML). Original error: %s',
                    $this->wsdl_url,
                    $e->getMessage()
                );
            }
            
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Geniki SOAP authentication failed',
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'fault_string' => $e->faultstring ?? '',
                'fault_code' => $e->faultcode ?? '',
                'wsdl_url' => $this->wsdl_url,
                'courier' => 'Geniki',
            ));
            
            return new WP_Error('geniki_soap_fault', 'Geniki SOAP authentication failed: ' . $error_message, array(
                'fault_code' => $e->faultcode ?? '',
                'fault_string' => $e->faultstring ?? '',
                'wsdl_url' => $this->wsdl_url,
            ));
        } catch (Exception $e) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Geniki authentication exception',
                'error_message' => $e->getMessage(),
                'courier' => 'Geniki',
            ));
            
            return new WP_Error('geniki_auth_exception', 'Geniki authentication exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Make TrackAndTrace SOAP request
     * 
     * @param string $auth_key Authentication key
     * @param string $voucher_code Voucher number
     * @param string $language Language code ('el' or 'en')
     * @return array|WP_Error
     */
    private function make_track_and_trace_request($auth_key, $voucher_code, $language = 'en') {
        // Check if SOAP extension is available
        if (!class_exists('SoapClient')) {
            return new WP_Error('soap_not_available', 'SOAP extension is not available. Please enable PHP SOAP extension.');
        }
        
        try {
            // Create SOAP client with improved options
            $soap_options = array(
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'encoding' => 'UTF-8',
                'connection_timeout' => 15,
                'stream_context' => stream_context_create(array(
                    'http' => array(
                        'timeout' => 15,
                        'user_agent' => 'WordPress/CourierIntelligence',
                    ),
                )),
            );
            
            $client = new SoapClient($this->wsdl_url, $soap_options);
            
            // Call TrackAndTrace method
            $result = $client->TrackAndTrace(array(
                'authKey' => $auth_key,
                'voucherNo' => $voucher_code,
                'language' => $language,
            ));
            
            // Convert SOAP result to array
            $response = json_decode(json_encode($result), true);
            
            // Extract TrackAndTraceResult
            $track_result = $response['TrackAndTraceResult'] ?? $response;
            
            // Check result code (0 = success)
            $result_code = isset($track_result['Result']) ? intval($track_result['Result']) : -1;
            
            if ($result_code !== 0) {
                $error_message = $this->get_error_message($result_code);
                
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'message' => 'Geniki TrackAndTrace failed',
                    'result_code' => $result_code,
                    'error_message' => $error_message,
                    'voucher_code' => $voucher_code,
                    'courier' => 'Geniki',
                ));
                
                // If error is "Invalid key" (11), clear cache and try to re-authenticate once
                if ($result_code === 11) {
                    $this->auth_key = null;
                    $this->auth_key_expires = null;
                    
                    // Try to re-authenticate and retry once
                    $new_auth_key = $this->authenticate();
                    if (!is_wp_error($new_auth_key)) {
                        // Retry the request
                        return $this->make_track_and_trace_request($new_auth_key, $voucher_code, $language);
                    }
                }
                
                return new WP_Error('geniki_tracking_error', $error_message, array(
                    'result_code' => $result_code,
                ));
            }
            
            return $track_result;
            
        } catch (SoapFault $e) {
            $error_message = $e->getMessage();
            
            // Provide more helpful error messages for common WSDL issues
            if (strpos($error_message, 'Parsing WSDL') !== false || strpos($error_message, 'Couldn\'t load') !== false) {
                $error_message = sprintf(
                    'Failed to load WSDL from %s. Please verify: 1) The endpoint URL is correct and includes ?WSDL, 2) The server is accessible, 3) The server is returning valid WSDL (not HTML). Original error: %s',
                    $this->wsdl_url,
                    $e->getMessage()
                );
            }
            
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Geniki SOAP TrackAndTrace failed',
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'fault_string' => $e->faultstring ?? '',
                'fault_code' => $e->faultcode ?? '',
                'voucher_code' => $voucher_code,
                'wsdl_url' => $this->wsdl_url,
                'courier' => 'Geniki',
            ));
            
            return new WP_Error('geniki_soap_fault', 'Geniki SOAP request failed: ' . $error_message, array(
                'fault_code' => $e->faultcode ?? '',
                'fault_string' => $e->faultstring ?? '',
                'wsdl_url' => $this->wsdl_url,
            ));
        } catch (Exception $e) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Geniki TrackAndTrace exception',
                'error_message' => $e->getMessage(),
                'voucher_code' => $voucher_code,
                'courier' => 'Geniki',
            ));
            
            return new WP_Error('geniki_tracking_exception', 'Geniki tracking exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Get error message from result code
     * 
     * @param int $result_code Error code
     * @return string Error message
     */
    private function get_error_message($result_code) {
        $error_messages = array(
            0 => 'OK',
            1 => 'Authentication failed',
            2 => 'Not implemented',
            3 => 'No data',
            4 => 'Invalid operation',
            5 => 'Max voucher No. reached',
            6 => 'Max subvoucher No. reached',
            8 => 'SQL error',
            9 => 'Doesn\'t exist',
            10 => 'Not authorized',
            11 => 'Invalid key (authentication key expired or invalid)',
            12 => 'Run-time error',
            13 => 'Job canceled',
            14 => 'Server busy',
            15 => 'Request limit reached',
        );
        
        return $error_messages[$result_code] ?? 'Unknown error (code: ' . $result_code . ')';
    }
    
    /**
     * Parse tracking response
     * 
     * Converts Geniki's TrackAndTraceResult into normalized format
     * 
     * @param array $response TrackAndTraceResult from API
     * @return array|WP_Error Tracking data
     */
    private function parse_tracking_response($response) {
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Convert object to array if needed
        if (is_object($response)) {
            $response = json_decode(json_encode($response), true);
        }
        
        // Extract checkpoints
        $events = array();
        $checkpoints = $response['Checkpoints'] ?? array();
        
        if (is_array($checkpoints)) {
            // Handle both single checkpoint and array of checkpoints
            if (isset($checkpoints['StatusCode']) || isset($checkpoints['Status'])) {
                // Single checkpoint object
                $checkpoints = array($checkpoints);
            }
            
            foreach ($checkpoints as $checkpoint) {
                if (is_object($checkpoint)) {
                    $checkpoint = json_decode(json_encode($checkpoint), true);
                }
                
                if (!empty($checkpoint['Status']) || !empty($checkpoint['StatusCode'])) {
                    // Parse StatusDate
                    $status_date = $checkpoint['StatusDate'] ?? '';
                    $date = '';
                    $time = '';
                    $datetime = '';
                    
                    if (!empty($status_date)) {
                        // StatusDate is a DateTime object, convert to string first
                        if (is_array($status_date)) {
                            // SOAP DateTime as array
                            $datetime = isset($status_date['date']) ? $status_date['date'] : '';
                        } else {
                            $datetime = $status_date;
                        }
                        
                        // Try to parse various formats
                        if (!empty($datetime)) {
                            // Try ISO 8601 format
                            $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $datetime);
                            if ($dt === false) {
                                // Try with timezone
                                $dt = DateTime::createFromFormat('Y-m-d\TH:i:sP', $datetime);
                            }
                            if ($dt === false) {
                                // Try with milliseconds
                                $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.u', $datetime);
                            }
                            if ($dt === false) {
                                // Try simple date format
                                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
                            }
                            
                            if ($dt !== false) {
                                $date = $dt->format('Y-m-d');
                                $time = $dt->format('H:i');
                            } else {
                                // Fallback: use as-is and try to extract date/time
                                $date = substr($datetime, 0, 10);
                                $time = substr($datetime, 11, 5);
                            }
                        }
                    }
                    
                    $events[] = array(
                        'date' => $date,
                        'time' => $time,
                        'datetime' => $datetime,
                        'station' => $checkpoint['Shop'] ?? '',
                        'status_title' => $checkpoint['Status'] ?? '',
                        'status_code' => $checkpoint['StatusCode'] ?? '',
                        'remarks' => '',
                    );
                }
            }
        }
        
        // Sort events by datetime (newest first)
        usort($events, function($a, $b) {
            $time_a = !empty($a['datetime']) ? strtotime($a['datetime']) : (strtotime($a['date'] . ' ' . $a['time']) ?: 0);
            $time_b = !empty($b['datetime']) ? strtotime($b['datetime']) : (strtotime($b['date'] . ' ' . $b['time']) ?: 0);
            return $time_b - $time_a; // Newest first
        });
        
        // Extract status and delivery information
        $status = $response['Status'] ?? '';
        $delivery_date_raw = $response['DeliveryDate'] ?? null;
        $consignee = $response['Consignee'] ?? '';
        $delivered_at = $response['DeliveredAt'] ?? '';
        
        // Parse delivery date first to check if it's valid (not a placeholder)
        $delivery_date = '';
        $delivery_time = '';
        $has_valid_delivery_date = false;
        
        if (!empty($delivery_date_raw)) {
            // DeliveryDate is a DateTime object
            if (is_array($delivery_date_raw)) {
                $delivery_datetime = $delivery_date_raw['date'] ?? '';
            } else {
                $delivery_datetime = $delivery_date_raw;
            }
            
            if (!empty($delivery_datetime)) {
                // Try to parse various formats
                $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $delivery_datetime);
                if ($dt === false) {
                    $dt = DateTime::createFromFormat('Y-m-d\TH:i:sP', $delivery_datetime);
                }
                if ($dt === false) {
                    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $delivery_datetime);
                }
                
                if ($dt !== false) {
                    $parsed_date = $dt->format('Y-m-d');
                    // Check if it's not a placeholder date (like 0001-01-01)
                    if ($parsed_date !== '0001-01-01' && $parsed_date > '1900-01-01') {
                        $delivery_date = $parsed_date;
                        $delivery_time = $dt->format('H:i');
                        $has_valid_delivery_date = true;
                    }
                } else {
                    $parsed_date = substr($delivery_datetime, 0, 10);
                    // Check if it's not a placeholder date
                    if ($parsed_date !== '0001-01-01' && $parsed_date > '1900-01-01') {
                        $delivery_date = $parsed_date;
                        $delivery_time = substr($delivery_datetime, 11, 5);
                        $has_valid_delivery_date = true;
                    }
                }
            }
        }
        
        // Determine if delivered - only if we have explicit delivery indicators
        // Don't rely on placeholder dates or empty consignee fields
        $is_delivered = (
            strtoupper($status) === 'DELIVERED' ||
            $has_valid_delivery_date ||
            (!empty($consignee) && strtoupper($status) !== 'IN TRANSIT')
        );
        
        // Get current status title (from latest checkpoint or status field)
        $current_status_title = '';
        if (!empty($events)) {
            $latest_event = reset($events);
            $current_status_title = $latest_event['status_title'] ?? '';
        }
        if (empty($current_status_title)) {
            $current_status_title = $status;
        }
        
        // Map Geniki status to normalized status
        $current_status = $this->map_geniki_status($status, $is_delivered, $events);
        
        // Determine if returned
        $is_returned = ($current_status === 'returned');
        
        $tracking_data = array(
            'success' => true,
            'delivered' => $is_delivered,
            'returned' => $is_returned,
            'status' => $current_status,
            'status_title' => $current_status_title,
            'events' => $events,
            'status_counter' => count($events),
            'voucher_code' => null, // Geniki doesn't return voucher code in tracking
            'reference_number' => null,
        );
        
        // Delivery information
        if ($is_delivered) {
            $tracking_data['delivery_date'] = $delivery_date;
            $tracking_data['delivery_time'] = $delivery_time;
            $tracking_data['recipient_name'] = $consignee;
            $tracking_data['delivered_at'] = $delivered_at; // RECIPIENT/RETURN TO SENDER
        }
        
        // Additional information
        $tracking_data['returning_service_voucher'] = $response['ReturningServiceVoucher'] ?? null;
        
        // Include raw response for debugging/advanced use
        $tracking_data['raw_response'] = $response;
        
        return $tracking_data;
    }
    
    /**
     * Map Geniki Taxidromiki status to normalized status
     * 
     * Geniki API returns status as: "DELIVERED", "IN TRANSIT", "IN RETURN"
     * Also uses status codes in checkpoints (complete list from PDF):
     * 
     * Created: C_NW, C_SC
     * Delivered: C_W2, C_P1, C_W3, C_S2
     * Returned: C_E1, C_S3, C_EA_EP
     * Issue: C_P4, C_S5, C_S8, C_S6, C_SA, C_SB, C_EA_AG, C_EA_AK, C_EA_AP, C_EA_AS, C_EA_DD, C_EA_LA, C_EA_LD, C_SF
     * In Transit: C_A1, C_A3, C_K8, C_H1, C_H2, C_S0, C_S1, C_S7, C_SD, C_EA_DA, C_EA_DP, C_EA_KS, C_SE, C_D2, C_S4, C_S9
     * 
     * Normalized statuses: created, in_transit, delivered, returned, issue, unknown
     * 
     * @param string $status Status from API (DELIVERED/IN TRANSIT/IN RETURN)
     * @param bool $is_delivered Whether package is delivered
     * @param array $events Array of checkpoint events
     * @return string Normalized status: created, in_transit, delivered, returned, issue, unknown
     */
    private function map_geniki_status($status, $is_delivered, $events = array()) {
        // Check latest checkpoint for detailed status FIRST (most reliable)
        // This takes priority over the $is_delivered flag which might be incorrectly set
        if (!empty($events)) {
            $latest_event = reset($events);
            $status_code = $latest_event['status_code'] ?? '';
            $status_title = strtoupper($latest_event['status_title'] ?? '');
            
            // Priority 1: Created - check FIRST to avoid false positives
            // Shipment created/printed: C_NW, C_SC
            if (in_array($status_code, array('C_NW', 'C_SC')) ||
                strpos($status_title, 'CREATED') !== false ||
                strpos($status_title, 'PRINTED') !== false ||
                strpos($status_title, 'LABEL') !== false) {
                return 'created';
            }
            
            // Priority 2: Delivered - check status codes and titles
            // Delivered: C_W2, C_P1, C_W3, C_S2
            if (in_array($status_code, array('C_W2', 'C_P1', 'C_W3', 'C_S2')) ||
                strpos($status_title, 'DELIVERED') !== false ||
                strpos($status_title, 'COLLECTED') !== false ||
                strpos($status_title, 'PICKEDUP') !== false ||
                strpos($status_title, 'PICKED UP') !== false) {
                return 'delivered';
            }
            
            // Priority 3: Returned - check status codes and titles
            // Return to sender: C_E1, C_S3, C_EA_EP
            if (in_array($status_code, array('C_E1', 'C_S3', 'C_EA_EP')) ||
                strpos($status_title, 'RETURN') !== false ||
                strpos($status_title, 'RETURNED') !== false) {
                return 'returned';
            }
            
            // Priority 4: Issue - check status codes and titles
            // Cancellation: C_P4, C_S5, C_S8
            // Attempted Delivery Issues: C_EA_AG, C_EA_AK, C_EA_AP, C_EA_AS, C_EA_DD, C_EA_LA, C_EA_LD, C_SF
            // International Issues: C_S6, C_SA, C_SB
            if (in_array($status_code, array(
                    'C_P4',      // Shipment canceled
                    'C_S5',      // Shipment cancelled
                    'C_S8',      // Shipment cancelled
                    'C_S6',      // Shipment lost
                    'C_SA',      // Shipment damaged
                    'C_SB',      // Shipment destroyed
                    'C_EA_AG',   // Unknown recipient
                    'C_EA_AK',   // Damaged
                    'C_EA_AP',   // Refusal to receive
                    'C_EA_AS',   // Absent
                    'C_EA_DD',   // Not Distributed
                    'C_EA_LA',   // Missorted
                    'C_EA_LD',   // Wrong Address
                    'C_SF',      // Attempted delivery
                )) ||
                strpos($status_title, 'CANCEL') !== false ||
                strpos($status_title, 'DAMAGED') !== false ||
                strpos($status_title, 'LOST') !== false ||
                strpos($status_title, 'DESTROYED') !== false ||
                strpos($status_title, 'REFUSAL') !== false ||
                strpos($status_title, 'ABSENT') !== false ||
                strpos($status_title, 'WRONG ADDRESS') !== false ||
                strpos($status_title, 'MISSORTED') !== false ||
                strpos($status_title, 'UNKNOWN RECIPIENT') !== false ||
                strpos($status_title, 'NOT DISTRIBUTED') !== false ||
                strpos($status_title, 'ATTEMPTED DELIVERY') !== false) {
                return 'issue';
            }
            
            // Priority 5: In Transit - check status codes and titles
            // Arrivals/Departures: C_A1, C_A3, C_K8, C_H1, C_H2
            // International In Transit: C_S0, C_S1, C_S7, C_SD
            // Routing/Rescheduled: C_EA_DA, C_EA_DP, C_EA_KS, C_SE
            // On Hold: C_D2, C_S4, C_S9
            if (in_array($status_code, array(
                    'C_A1',      // Arrival at Service Point
                    'C_A3',      // Out for Delivery
                    'C_K8',      // Departure from Service Point
                    'C_H1',      // Arrival at hub
                    'C_H2',      // Departure from hub
                    'C_S0',      // Out for delivery (International)
                    'C_S1',      // In transit (International)
                    'C_S7',      // Shipment rerouting
                    'C_SD',      // Arrival at HUB (International)
                    'C_EA_DA',   // Routing
                    'C_EA_DP',   // Delivery in 2–3 days
                    'C_EA_KS',   // Delivery Rescheduled
                    'C_SE',      // Delivery rescheduled (International)
                    'C_D2',      // Awaiting Pick Up
                    'C_S4',      // On hold (International)
                    'C_S9',      // On hold (International)
                )) ||
                strpos($status_title, 'ARRIVAL') !== false ||
                strpos($status_title, 'DEPARTURE') !== false ||
                strpos($status_title, 'OUT FOR DELIVERY') !== false ||
                strpos($status_title, 'IN TRANSIT') !== false ||
                strpos($status_title, 'TRANSPORT') !== false ||
                strpos($status_title, 'ROUTING') !== false ||
                strpos($status_title, 'REROUTING') !== false ||
                strpos($status_title, 'RESCHEDULED') !== false ||
                strpos($status_title, 'ON HOLD') !== false ||
                strpos($status_title, 'AWAITING') !== false ||
                strpos($status_title, 'HUB') !== false) {
                return 'in_transit';
            }
        }
        
        // Fallback to status string and flags (less reliable)
        $status_upper = strtoupper($status);
        
        // Priority 1: Delivered - check status string
        if ($status_upper === 'DELIVERED' || $is_delivered) {
            return 'delivered';
        }
        
        // Priority 2: Returned - check status string
        if ($status_upper === 'IN RETURN') {
            return 'returned';
        }
        
        // Priority 3: In Transit - check status string
        if ($status_upper === 'IN TRANSIT') {
            return 'in_transit';
        }
        
        // Fallback: if we have events but don't recognize the status, assume in_transit
        if (!empty($events)) {
            return 'in_transit';
        }
        
        // No status information at all
        return 'unknown';
    }
}

