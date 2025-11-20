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
        
        // Determine if delivered
        $is_delivered = (
            strtoupper($status) === 'DELIVERED' ||
            !empty($delivery_date_raw) ||
            !empty($consignee)
        );
        
        // Parse delivery date
        $delivery_date = '';
        $delivery_time = '';
        
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
                    $delivery_date = $dt->format('Y-m-d');
                    $delivery_time = $dt->format('H:i');
                } else {
                    $delivery_date = substr($delivery_datetime, 0, 10);
                    $delivery_time = substr($delivery_datetime, 11, 5);
                }
            }
        }
        
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
        
        $tracking_data = array(
            'success' => true,
            'delivered' => $is_delivered,
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
     * Also uses status codes in checkpoints (C_W2 = delivered, C_E1 = return, etc.)
     * 
     * @param string $status Status from API (DELIVERED/IN TRANSIT/IN RETURN)
     * @param bool $is_delivered Whether package is delivered
     * @param array $events Array of checkpoint events
     * @return string Normalized status: created, in_transit, delivered, returned, issue
     */
    private function map_geniki_status($status, $is_delivered, $events = array()) {
        // If delivered, it's definitely delivered
        if ($is_delivered || strtoupper($status) === 'DELIVERED') {
            return 'delivered';
        }
        
        // Check status string
        $status_upper = strtoupper($status);
        
        if ($status_upper === 'IN RETURN') {
            return 'returned';
        }
        
        // Check latest checkpoint status code
        if (!empty($events)) {
            $latest_event = reset($events);
            $status_code = $latest_event['status_code'] ?? '';
            
            // Map status codes to normalized status
            switch ($status_code) {
                case 'C_W2':  // Shipment Delivered
                    return 'delivered';
                    
                case 'C_E1':  // Return to sender
                case 'C_S3':  // Int'l Shipment Status: Returned
                    return 'returned';
                    
                case 'C_NW':  // Shipment label created/printed
                case 'C_SC':  // Int'l Shipment Status: Shipment label created/printed
                    return 'created';
                    
                case 'C_P4':  // Shipment canceled
                case 'C_S5':  // Int'l Shipment Status: Shipment Cancelled
                case 'C_S8':  // Int'l Shipment Status: Shipment Cancelled
                    return 'issue';
                    
                case 'C_EA_AG':  // Attempted Delivery - Unknown recipient
                case 'C_EA_AK':  // Attempted Delivery - Damaged
                case 'C_EA_AP':  // Attempted Delivery - Refusal to receive
                case 'C_EA_AS':  // Attempted Delivery - Absent recipient
                case 'C_EA_DA':  // Attempted Delivery - Shipment routing
                case 'C_EA_DD':  // Attempted Delivery - Not Distributed
                case 'C_EA_EP':  // Attempted Delivery - Return
                case 'C_EA_LA':  // Attempted Delivery - Shipment Missorted / Misrouted
                case 'C_EA_LD':  // Attempted Delivery - Wrong Address
                case 'C_SA':     // Int'l Shipment Status: Shipment Damaged
                case 'C_SB':     // Int'l Shipment Status: Shipment Destroyed
                case 'C_S6':     // Int'l Shipment Status: Shipment Lost
                    return 'issue';
                    
                case 'C_A1':  // Arrival at Service Point
                case 'C_A3':  // Out for Delivery
                case 'C_K8':  // Departure from Service Point
                case 'C_H1':  // Arrival at hub
                case 'C_H2':  // Departure from hub
                case 'C_S0':  // Int'l Shipment Status: Out for Delivery
                case 'C_S1':  // Int'l Shipment Status: In Transit
                case 'C_SF':  // Int'l Shipment Status: Attempted Delivery
                    return 'in_transit';
            }
            
            // Check status title for keywords
            $status_title = strtoupper($latest_event['status_title'] ?? '');
            
            if (strpos($status_title, 'DELIVERED') !== false) {
                return 'delivered';
            }
            
            if (strpos($status_title, 'RETURN') !== false) {
                return 'returned';
            }
            
            if (strpos($status_title, 'CANCEL') !== false ||
                strpos($status_title, 'DAMAGED') !== false ||
                strpos($status_title, 'LOST') !== false ||
                strpos($status_title, 'REFUSAL') !== false ||
                strpos($status_title, 'ABSENT') !== false ||
                strpos($status_title, 'WRONG ADDRESS') !== false) {
                return 'issue';
            }
        }
        
        // Default based on status string
        if ($status_upper === 'IN TRANSIT') {
            return 'in_transit';
        }
        
        // Fallback: if we have events, assume in_transit
        if (!empty($events)) {
            return 'in_transit';
        }
        
        // No status information at all
        return 'unknown';
    }
}

