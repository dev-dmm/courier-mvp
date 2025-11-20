<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Speedex Courier Tracking API Client
 * 
 * Minimal implementation for tracking existing vouchers only.
 * 
 * IMPORTANT: This class uses SOAP/WSDL web service.
 * 
 * Speedex API Specifications:
 * - Base URL: https://spdxws.gr/accesspoint.asmx
 * - Test URL: https://devspdxws.gr/accesspoint.asmx
 * - WSDL: Available at {base_url}?WSDL (publicly accessible)
 * - Authentication: Required via CreateSession method (returns sessionID)
 * - Tracking: GetTraceByVoucher method returns checkpoints
 * 
 * Required Credentials:
 * - Username
 * - Password
 * 
 * Usage:
 *   $client = new Courier_Intelligence_Speedex_API_Client();
 *   $result = $client->get_voucher_status('1234567890');
 * 
 * This class only READS tracking information for existing vouchers.
 * It does NOT create vouchers (vouchers come from order meta keys via other plugins).
 */
class Courier_Intelligence_Speedex_API_Client {
    
    private $wsdl_url;
    private $username;
    private $password;
    private $test_mode;
    private $session_id;  // Cached session ID
    private $session_expires;  // When the session expires
    
    /**
     * Constructor
     */
    public function __construct($courier_settings = array()) {
        // Get Speedex-specific settings
        $settings = get_option('courier_intelligence_settings', array());
        $speedex_settings = $settings['couriers']['speedex'] ?? array();
        
        // Merge with provided settings
        $speedex_settings = array_merge($speedex_settings, $courier_settings);
        
        // Test mode flag
        $this->test_mode = isset($speedex_settings['test_mode']) && $speedex_settings['test_mode'] === 'yes';
        
        // Set WSDL URL - ensure ?WSDL is appended if not present
        if ($this->test_mode) {
            $endpoint = $speedex_settings['test_endpoint'] ?? 'https://devspdxws.gr/accesspoint.asmx';
        } else {
            $endpoint = $speedex_settings['api_endpoint'] ?? 'https://spdxws.gr/accesspoint.asmx';
        }
        
        // Ensure ?WSDL is appended to the endpoint URL
        if (strpos($endpoint, '?WSDL') === false && strpos($endpoint, '?wsdl') === false) {
            $this->wsdl_url = rtrim($endpoint, '/') . '?WSDL';
        } else {
            $this->wsdl_url = $endpoint;
        }
        
        // Speedex authentication credentials
        $this->username = $speedex_settings['username'] ?? '';
        $this->password = $speedex_settings['password'] ?? '';
        
        // Initialize session cache
        $this->session_id = null;
        $this->session_expires = null;
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
     *     'status_title' => 'Delivered',
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
     * Uses GetTraceByVoucher method to get complete tracking information.
     * 
     * @param string $voucher_code Voucher number
     * @return array|WP_Error Tracking data or error
     */
    public function track_shipment($voucher_code) {
        if (empty($this->username) || empty($this->password)) {
            return new WP_Error('missing_credentials', 'Speedex API credentials not configured');
        }
        
        // Authenticate first (or use cached session)
        $session_id = $this->create_session();
        if (is_wp_error($session_id)) {
            return $session_id;
        }
        
        // Make GetTraceByVoucher request
        $response = $this->make_trace_request($session_id, $voucher_code);
        
        // Destroy session after use
        $this->destroy_session($session_id);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $this->parse_tracking_response($response, $voucher_code);
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
            return new WP_Error('empty_wsdl_url', 'WSDL URL is empty. Please configure the Speedex API endpoint in settings.');
        }
        
        // Check if URL is valid
        if (!filter_var($wsdl_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_wsdl_url', sprintf('Invalid WSDL URL: %s', $wsdl_url));
        }
        
        // Try to fetch the WSDL to check if it's accessible
        $response = wp_remote_get($wsdl_url, array(
            'timeout' => 10,
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
     * Create session with Speedex API
     * 
     * Returns session ID that is used for subsequent API calls.
     * The session ID is cached to avoid unnecessary session creation.
     * 
     * @return string|WP_Error Session ID or error
     */
    private function create_session() {
        // Check if we have a valid cached session
        if ($this->session_id !== null && $this->session_expires !== null) {
            if (time() < $this->session_expires) {
                return $this->session_id;
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
            // Create SOAP client
            $soap_options = array(
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'encoding' => 'UTF-8',
                'connection_timeout' => 10,
            );
            
            $client = new SoapClient($this->wsdl_url, $soap_options);
            
            // Call CreateSession method
            $result = $client->CreateSession(array(
                'username' => $this->username,
                'password' => $this->password,
            ));
            
            // Convert SOAP result to array
            $response = json_decode(json_encode($result), true);
            
            // Check return code (1 = success)
            $return_code = isset($response['returnCode']) ? intval($response['returnCode']) : -1;
            
            if ($return_code !== 1) {
                $error_message = 'Session creation failed';
                if ($return_code == 100) {
                    $error_message = 'Invalid username or password';
                } else {
                    $return_message = $response['returnMessage'] ?? 'Unknown error';
                    $error_message = sprintf('Session creation failed (Code: %d, Message: %s)', $return_code, $return_message);
                }
                
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'message' => 'Speedex session creation failed',
                    'return_code' => $return_code,
                    'return_message' => $response['returnMessage'] ?? '',
                    'courier' => 'Speedex',
                ));
                
                return new WP_Error('speedex_session_failed', $error_message, array(
                    'return_code' => $return_code,
                ));
            }
            
            // Get session ID
            $session_id = $response['sessionId'] ?? '';
            
            if (empty($session_id)) {
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'message' => 'Speedex session creation returned empty session ID',
                    'courier' => 'Speedex',
                ));
                
                return new WP_Error('speedex_session_empty', 'Session creation succeeded but no session ID was returned');
            }
            
            // Cache the session ID (assume it's valid for 30 minutes)
            $this->session_id = $session_id;
            $this->session_expires = time() + 1800; // 30 minutes
            
            return $session_id;
            
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
                'message' => 'Speedex SOAP session creation failed',
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'fault_string' => $e->faultstring ?? '',
                'fault_code' => $e->faultcode ?? '',
                'wsdl_url' => $this->wsdl_url,
                'courier' => 'Speedex',
            ));
            
            return new WP_Error('speedex_soap_fault', $error_message, array(
                'fault_code' => $e->faultcode ?? '',
                'fault_string' => $e->faultstring ?? '',
                'wsdl_url' => $this->wsdl_url,
            ));
        } catch (Exception $e) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Speedex session creation exception',
                'error_message' => $e->getMessage(),
                'courier' => 'Speedex',
            ));
            
            return new WP_Error('speedex_session_exception', 'Speedex session creation exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Make GetTraceByVoucher SOAP request
     * 
     * @param string $session_id Session ID
     * @param string $voucher_code Voucher number
     * @return array|WP_Error
     */
    private function make_trace_request($session_id, $voucher_code) {
        // Check if SOAP extension is available
        if (!class_exists('SoapClient')) {
            return new WP_Error('soap_not_available', 'SOAP extension is not available. Please enable PHP SOAP extension.');
        }
        
        try {
            // Create SOAP client
            $soap_options = array(
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'encoding' => 'UTF-8',
                'connection_timeout' => 10,
            );
            
            $client = new SoapClient($this->wsdl_url, $soap_options);
            
            // Call GetTraceByVoucher method
            // According to Speedex API docs, the method expects:
            // - sessionID: string (session ID from CreateSession)
            // - VoucherID: string (voucher number to track)
            $result = $client->GetTraceByVoucher(array(
                'sessionID' => $session_id,
                'VoucherID' => $voucher_code,
            ));
            
            // Convert SOAP result to array
            // The response is wrapped in GetTraceByVoucherResponse according to the API docs
            $response = json_decode(json_encode($result), true);
            
            // Handle response structure - may be wrapped in GetTraceByVoucherResponse
            if (isset($response['GetTraceByVoucherResult'])) {
                $response = $response['GetTraceByVoucherResult'];
            } elseif (isset($response['GetTraceByVoucherResponse'])) {
                $response = $response['GetTraceByVoucherResponse'];
            }
            
            // Check return code (1 = success according to Speedex API)
            // returnCode and returnMessage are at the root level of the response
            $return_code = isset($response['returnCode']) ? intval($response['returnCode']) : -1;
            
            if ($return_code !== 1) {
                $return_message = $response['returnMessage'] ?? 'Unknown error';
                $error_message = sprintf('Tracking failed: %s (Code: %d)', $return_message, $return_code);
                
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'message' => 'Speedex GetTraceByVoucher failed',
                    'return_code' => $return_code,
                    'return_message' => $return_message,
                    'voucher_code' => $voucher_code,
                    'raw_response' => $response,
                    'courier' => 'Speedex',
                ));
                
                return new WP_Error('speedex_tracking_error', $error_message, array(
                    'return_code' => $return_code,
                    'return_message' => $return_message,
                ));
            }
            
            return $response;
            
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
                'message' => 'Speedex SOAP GetTraceByVoucher failed',
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'fault_string' => $e->faultstring ?? '',
                'fault_code' => $e->faultcode ?? '',
                'voucher_code' => $voucher_code,
                'wsdl_url' => $this->wsdl_url,
                'courier' => 'Speedex',
            ));
            
            return new WP_Error('speedex_soap_fault', 'Speedex SOAP request failed: ' . $error_message, array(
                'fault_code' => $e->faultcode ?? '',
                'fault_string' => $e->faultstring ?? '',
                'wsdl_url' => $this->wsdl_url,
            ));
        } catch (Exception $e) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Speedex GetTraceByVoucher exception',
                'error_message' => $e->getMessage(),
                'voucher_code' => $voucher_code,
                'courier' => 'Speedex',
            ));
            
            return new WP_Error('speedex_tracking_exception', 'Speedex tracking exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Destroy session with Speedex API
     * 
     * @param string $session_id Session ID to destroy
     * @return bool Success status
     */
    private function destroy_session($session_id) {
        if (empty($session_id)) {
            return false;
        }
        
        // Clear cached session
        if ($this->session_id === $session_id) {
            $this->session_id = null;
            $this->session_expires = null;
        }
        
        // Check if SOAP extension is available
        if (!class_exists('SoapClient')) {
            return false;
        }
        
        try {
            // Create SOAP client
            $soap_options = array(
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'encoding' => 'UTF-8',
                'connection_timeout' => 10,
            );
            
            $client = new SoapClient($this->wsdl_url, $soap_options);
            
            // Call DestroySession method
            $result = $client->DestroySession(array(
                'sessionID' => $session_id,
            ));
            
            // Convert SOAP result to array
            $response = json_decode(json_encode($result), true);
            
            // Check return code (1 = success)
            $return_code = isset($response['returnCode']) ? intval($response['returnCode']) : 1;
            
            return $return_code === 1;
            
        } catch (Exception $e) {
            // Log but don't fail - session destruction is not critical
            Courier_Intelligence_Logger::log('voucher', 'warning', array(
                'message' => 'Speedex session destruction failed',
                'error_message' => $e->getMessage(),
                'courier' => 'Speedex',
            ));
            
            return false;
        }
    }
    
    /**
     * Parse tracking response
     * 
     * Converts Speedex's GetTraceByVoucherResponse into normalized format.
     * 
     * According to Speedex API documentation, the response structure is:
     * <GetTraceByVoucherResponse>
     *   <checkpoints>
     *     <Checkpoint>
     *       <VoucherID>string</VoucherID>
     *       <StatusCode>string</StatusCode>
     *       <StatusDesc>string</StatusDesc>
     *       <BranchID>string</BranchID>
     *       <Branch>string</Branch>
     *       <CheckpointDate>dateTime</CheckpointDate>
     *       <SpeedexComments1>string</SpeedexComments1>
     *       <SpeedexComments2>string</SpeedexComments2>
     *       <Comments>string</Comments>
     *       <ClientRef1>string</ClientRef1>
     *       <ClientRef2>string</ClientRef2>
     *       <ClientRef3>string</ClientRef3>
     *       <ClientComments1>string</ClientComments1>
     *       <ClientComments2>string</ClientComments2>
     *       <ClientComments3>string</ClientComments3>
     *     </Checkpoint>
     *     ...
     *   </checkpoints>
     *   <returnCode>int</returnCode>
     *   <returnMessage>string</returnMessage>
     * </GetTraceByVoucherResponse>
     * 
     * @param array $response GetTraceByVoucherResponse from API
     * @param string $voucher_code Voucher number
     * @return array|WP_Error Tracking data
     */
    private function parse_tracking_response($response, $voucher_code) {
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Convert object to array if needed
        if (is_object($response)) {
            $response = json_decode(json_encode($response), true);
        }
        
        // Extract checkpoints
        // The response structure has checkpoints containing Checkpoint elements
        $events = array();
        $checkpoints = $response['checkpoints'] ?? array();
        
        if (is_array($checkpoints)) {
            // Handle both single checkpoint and array of checkpoints
            // SOAP may return a single Checkpoint object or an array of Checkpoint objects
            if (isset($checkpoints['Checkpoint'])) {
                $checkpoint_list = $checkpoints['Checkpoint'];
                // If it's a single checkpoint (has VoucherID key directly), wrap it in an array
                if (is_array($checkpoint_list) && isset($checkpoint_list['VoucherID']) && !isset($checkpoint_list[0])) {
                    // Single checkpoint object (associative array)
                    $checkpoint_list = array($checkpoint_list);
                } elseif (!is_array($checkpoint_list)) {
                    // If it's not an array at all, wrap it
                    $checkpoint_list = array($checkpoint_list);
                }
                // If it's already an array of checkpoints (numeric keys), use it as-is
            } else {
                $checkpoint_list = array();
            }
            
            foreach ($checkpoint_list as $checkpoint) {
                if (is_object($checkpoint)) {
                    $checkpoint = json_decode(json_encode($checkpoint), true);
                }
                
                if (!empty($checkpoint['StatusDesc']) || !empty($checkpoint['StatusCode'])) {
                    // Parse CheckpointDate
                    $checkpoint_date = $checkpoint['CheckpointDate'] ?? '';
                    $date = '';
                    $time = '';
                    $datetime = '';
                    
                    if (!empty($checkpoint_date)) {
                        // CheckpointDate is a dateTime type in SOAP (ISO 8601 format)
                        // PHP SoapClient may return it as DateTime object, string, or array
                        $dt = null;
                        
                        if ($checkpoint_date instanceof DateTime) {
                            // Already a DateTime object
                            $dt = $checkpoint_date;
                        } elseif (is_array($checkpoint_date)) {
                            // SOAP DateTime as array (from json_decode)
                            if (isset($checkpoint_date['date'])) {
                                $datetime = $checkpoint_date['date'];
                            } elseif (isset($checkpoint_date[0])) {
                                $datetime = $checkpoint_date[0];
                            } else {
                                $datetime = '';
                            }
                        } else {
                            // String format
                            $datetime = (string) $checkpoint_date;
                        }
                        
                        // Parse datetime string if we don't have a DateTime object yet
                        if ($dt === null && !empty($datetime)) {
                            // Try ISO 8601 format (most common for SOAP dateTime)
                            $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $datetime);
                            if ($dt === false) {
                                // Try with timezone offset
                                $dt = DateTime::createFromFormat('Y-m-d\TH:i:sP', $datetime);
                            }
                            if ($dt === false) {
                                // Try with milliseconds
                                $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.u', $datetime);
                            }
                            if ($dt === false) {
                                // Try with milliseconds and timezone
                                $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $datetime);
                            }
                            if ($dt === false) {
                                // Try simple date format
                                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
                            }
                            if ($dt === false) {
                                // Try to parse as ISO 8601 with DateTime constructor
                                try {
                                    $dt = new DateTime($datetime);
                                } catch (Exception $e) {
                                    $dt = false;
                                }
                            }
                        }
                        
                        if ($dt !== false && $dt instanceof DateTime) {
                            $date = $dt->format('Y-m-d');
                            $time = $dt->format('H:i');
                            $datetime = $dt->format('Y-m-d\TH:i:s');
                        } else {
                            // Fallback: use as-is and try to extract date/time
                            $datetime_str = is_string($checkpoint_date) ? $checkpoint_date : (string) $datetime;
                            $date = substr($datetime_str, 0, 10);
                            $time = substr($datetime_str, 11, 5);
                            $datetime = $datetime_str;
                        }
                    }
                    
                    // Combine comments from all available fields
                    // According to API docs: SpeedexComments1, SpeedexComments2, Comments
                    $comments = array();
                    if (!empty($checkpoint['SpeedexComments1'])) {
                        $comments[] = trim($checkpoint['SpeedexComments1']);
                    }
                    if (!empty($checkpoint['SpeedexComments2'])) {
                        $comments[] = trim($checkpoint['SpeedexComments2']);
                    }
                    if (!empty($checkpoint['Comments'])) {
                        $comments[] = trim($checkpoint['Comments']);
                    }
                    // Also include client comments if available
                    if (!empty($checkpoint['ClientComments1'])) {
                        $comments[] = trim($checkpoint['ClientComments1']);
                    }
                    if (!empty($checkpoint['ClientComments2'])) {
                        $comments[] = trim($checkpoint['ClientComments2']);
                    }
                    if (!empty($checkpoint['ClientComments3'])) {
                        $comments[] = trim($checkpoint['ClientComments3']);
                    }
                    $remarks = implode(' ', array_filter($comments));
                    
                    $events[] = array(
                        'date' => $date,
                        'time' => $time,
                        'datetime' => $datetime,
                        'station' => $checkpoint['Branch'] ?? '',
                        'branch_id' => $checkpoint['BranchID'] ?? '',
                        'status_title' => $checkpoint['StatusDesc'] ?? '',
                        'status_code' => $checkpoint['StatusCode'] ?? '',
                        'remarks' => $remarks,
                        'voucher_id' => $checkpoint['VoucherID'] ?? $voucher_code,
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
        
        // Get current status (latest checkpoint)
        $current_status_title = '';
        $current_status_code = '';
        if (!empty($events)) {
            $latest_event = reset($events);
            $current_status_title = $latest_event['status_title'] ?? '';
            $current_status_code = $latest_event['status_code'] ?? '';
        }
        
        // Map Speedex status to normalized status
        $current_status = $this->map_speedex_status($current_status_code, $current_status_title, $events);
        
        // Determine if delivered based on status
        $is_delivered = $current_status === 'delivered';
        
        // Extract delivery information from latest event if delivered
        $delivery_date = '';
        $delivery_time = '';
        $recipient_name = '';
        
        if ($is_delivered && !empty($events)) {
            $latest_event = reset($events);
            $delivery_date = $latest_event['date'] ?? '';
            $delivery_time = $latest_event['time'] ?? '';
        }
        
        $tracking_data = array(
            'success' => true,
            'delivered' => $is_delivered,
            'status' => $current_status,
            'status_title' => $current_status_title,
            'events' => $events,
            'status_counter' => count($events),
            'voucher_code' => $voucher_code,
            'reference_number' => null,
        );
        
        // Delivery information
        if ($is_delivered) {
            $tracking_data['delivery_date'] = $delivery_date;
            $tracking_data['delivery_time'] = $delivery_time;
            $tracking_data['recipient_name'] = $recipient_name;
        }
        
        // Include raw response for debugging/advanced use
        $tracking_data['raw_response'] = $response;
        
        return $tracking_data;
    }
    
    /**
     * Map Speedex Courier status to normalized status
     * 
     * Speedex API returns status codes and descriptions in checkpoints.
     * This function maps them to normalized statuses: created, in_transit, delivered, returned, issue
     * 
     * @param string $status_code Status code from checkpoint
     * @param string $status_title Status description from checkpoint
     * @param array $events Array of checkpoint events
     * @return string Normalized status: created, in_transit, delivered, returned, issue
     */
    private function map_speedex_status($status_code, $status_title, $events = array()) {
        // Normalize status title for comparison
        $title_upper = mb_strtoupper($status_title, 'UTF-8');
        $code_upper = mb_strtoupper($status_code, 'UTF-8');
        
        // Delivered keywords
        if (strpos($title_upper, 'ΠΑΡΑΔΟΘΗΚΕ') !== false ||  // Delivered
            strpos($title_upper, 'ΠΑΡΑΔΟΣΗ') !== false ||  // Delivery
            strpos($title_upper, 'DELIVERED') !== false ||
            strpos($code_upper, 'DELIVERED') !== false) {
            return 'delivered';
        }
        
        // Returned keywords
        if (strpos($title_upper, 'ΕΠΙΣΤΡΟΦΗ') !== false ||  // Return
            strpos($title_upper, 'ΕΠΙΣΤΡΕΦΕΙ') !== false ||  // Returning
            strpos($title_upper, 'RETURN') !== false ||
            strpos($code_upper, 'RETURN') !== false) {
            return 'returned';
        }
        
        // Issue/Exception keywords
        if (strpos($title_upper, 'ΑΔΥΝΑΜΙΑ') !== false ||  // Failure
            strpos($title_upper, 'ΑΠΟΡΡΙΦΘΗΚΕ') !== false ||  // Rejected
            strpos($title_upper, 'ΠΡΟΒΛΗΜΑ') !== false ||  // Problem
            strpos($title_upper, 'ΑΚΥΡΩΘΗΚΕ') !== false ||  // Cancelled
            strpos($title_upper, 'FAILURE') !== false ||
            strpos($title_upper, 'REJECTED') !== false ||
            strpos($title_upper, 'CANCELLED') !== false ||
            strpos($title_upper, 'CANCELED') !== false) {
            return 'issue';
        }
        
        // Created keywords (label created/printed)
        if (strpos($title_upper, 'ΔΗΜΙΟΥΡΓΙΑ') !== false ||  // Creation
            strpos($title_upper, 'ΕΚΤΥΠΩΣΗ') !== false ||  // Printing
            strpos($title_upper, 'CREATED') !== false ||
            strpos($title_upper, 'PRINTED') !== false) {
            return 'created';
        }
        
        // In transit keywords
        if (strpos($title_upper, 'ΑΝΑΧΩΡΗΣΗ') !== false ||  // Departure
            strpos($title_upper, 'ΑΦΙΞΗ') !== false ||      // Arrival
            strpos($title_upper, 'ΜΕΤΑΦΟΡΑ') !== false ||  // Transport
            strpos($title_upper, 'ΣΤΟ ΚΕΝΤΡΟ') !== false ||  // At center
            strpos($title_upper, 'DEPARTURE') !== false ||
            strpos($title_upper, 'ARRIVAL') !== false ||
            strpos($title_upper, 'IN TRANSIT') !== false ||
            strpos($title_upper, 'TRANSPORT') !== false) {
            return 'in_transit';
        }
        
        // Fallback: if we have events but don't recognize the status, assume in_transit
        // (better than "unknown" if there's actual tracking data)
        if (!empty($events)) {
            return 'in_transit';
        }
        
        // No status information at all
        return 'unknown';
    }
}

