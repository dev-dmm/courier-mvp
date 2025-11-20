<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ACS Courier Tracking API Client
 * 
 * Minimal implementation for tracking existing vouchers only.
 * 
 * IMPORTANT: This class uses REST API (not SOAP like Elta).
 * 
 * ACS API Specifications:
 * - Base URL: https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest
 * - Authentication: Header AcsApiKey (lowercase 's', case-sensitive)
 * - Request format: JSON POST with ACSAlias and ACSInputParameters
 * - Response format: JSON with ACSExecution_HasError, ACSExecutionErrorMessage, ACSOutputResponce
 *   NOTE: The API uses "ACSOutputResponce" (with typo) not "ACSOutputResponse"
 * 
 * Required Credentials:
 * - API Key (AcsApiKey header - note lowercase 's')
 * - Company_ID
 * - Company_Password
 * - User_ID
 * - User_Password
 * 
 * Tracking Methods:
 * - ACS_Trackingsummary: Latest status update for a shipment
 * - ACS_TrackingDetails: Full tracking history with checkpoints
 * 
 * Usage:
 *   $client = new Courier_Intelligence_ACS_API_Client();
 *   $result = $client->get_voucher_status('7227889174');
 * 
 * This class only READS tracking information for existing vouchers.
 * It does NOT create vouchers (vouchers come from order meta keys via other plugins).
 */
class Courier_Intelligence_ACS_API_Client {
    
    private $api_base_url;
    private $api_key;           // ACSApiKey header
    private $company_id;        // Company_ID
    private $company_password;  // Company_Password
    private $user_id;           // User_ID
    private $user_password;     // User_Password
    private $test_mode;
    
    /**
     * Constructor
     */
    public function __construct($courier_settings = array()) {
        // Get ACS-specific settings
        $settings = get_option('courier_intelligence_settings', array());
        $acs_settings = $settings['couriers']['acs'] ?? array();
        
        // Merge with provided settings
        $acs_settings = array_merge($acs_settings, $courier_settings);
        
        // Set API base URL
        // Production: https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest
        $this->api_base_url = $acs_settings['api_endpoint'] ?? 'https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest';
        
        // ACS authentication credentials
        $this->api_key = $acs_settings['api_key'] ?? $acs_settings['acs_api_key'] ?? '';
        $this->company_id = $acs_settings['company_id'] ?? '';
        $this->company_password = $acs_settings['company_password'] ?? '';
        $this->user_id = $acs_settings['user_id'] ?? '';
        $this->user_password = $acs_settings['user_password'] ?? '';
        
        // Test mode flag
        $this->test_mode = isset($acs_settings['test_mode']) && $acs_settings['test_mode'] === 'yes';
        
        // Use test endpoint if available and in test mode
        if ($this->test_mode && isset($acs_settings['test_endpoint'])) {
            $this->api_base_url = $acs_settings['test_endpoint'];
        }
    }
    
    /**
     * Get voucher status and delivery information
     * 
     * This is the main method you'll use. It takes a voucher number and returns
     * all available tracking information including status, events, and delivery details.
     * 
     * @param string $voucher_code Voucher number (10 digits)
     * @return array|WP_Error Status and delivery information
     * 
     * Example response:
     *   array(
     *     'success' => true,
     *     'delivered' => true,
     *     'status' => 'delivered',
     *     'status_title' => 'Delivery to consignee',
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
     * Uses both ACS_Trackingsummary and ACS_TrackingDetails to get complete information.
     * 
     * @param string $voucher_code Voucher number
     * @return array|WP_Error Tracking data or error
     */
    public function track_shipment($voucher_code) {
        if (empty($this->api_key) || empty($this->company_id) || empty($this->company_password) || 
            empty($this->user_id) || empty($this->user_password)) {
            return new WP_Error('missing_credentials', 'ACS API credentials not configured');
        }
        
        // Get tracking summary (latest status)
        $summary = $this->get_tracking_summary($voucher_code);
        if (is_wp_error($summary)) {
            return $summary;
        }
        
        // Get tracking details (full history)
        $details = $this->get_tracking_details($voucher_code);
        if (is_wp_error($details)) {
            // If details fail but summary succeeded, use summary only
            return $this->parse_tracking_response($summary, array());
        }
        
        return $this->parse_tracking_response($summary, $details);
    }
    
    /**
     * Get tracking summary (latest status)
     * 
     * ACSAlias: ACS_Trackingsummary
     * 
     * @param string $voucher_code Voucher number
     * @return array|WP_Error
     */
    private function get_tracking_summary($voucher_code) {
        $request_data = array(
            'ACSAlias' => 'ACS_Trackingsummary',
            'ACSInputParameters' => array(
                'Company_ID' => $this->company_id,
                'Company_Password' => $this->company_password,
                'User_ID' => $this->user_id,
                'User_Password' => $this->user_password,
                'Language' => 'EN',
                'Voucher_No' => $voucher_code,
            ),
        );
        
        return $this->make_rest_request($request_data);
    }
    
    /**
     * Get tracking details (full history)
     * 
     * ACSAlias: ACS_TrackingDetails
     * 
     * @param string $voucher_code Voucher number
     * @return array|WP_Error
     */
    private function get_tracking_details($voucher_code) {
        $request_data = array(
            'ACSAlias' => 'ACS_TrackingDetails',
            'ACSInputParameters' => array(
                'Company_ID' => $this->company_id,
                'Company_Password' => $this->company_password,
                'User_ID' => $this->user_id,
                'User_Password' => $this->user_password,
                'Language' => 'EN',
                'Voucher_No' => $voucher_code,
            ),
        );
        
        return $this->make_rest_request($request_data);
    }
    
    /**
     * Make REST API request to ACS Web Services
     * 
     * @param array $request_data Request data with ACSAlias and ACSInputParameters
     * @return array|WP_Error
     */
    private function make_rest_request($request_data) {
        // Prepare headers
        // NOTE: Header must be "AcsApiKey" (lowercase 's'), not "ACSApiKey"
        $headers = array(
            'Content-Type' => 'application/json',
            'AcsApiKey' => $this->api_key,
        );
        
        // Make POST request
        $response = wp_remote_post($this->api_base_url, array(
            'headers' => $headers,
            'body' => json_encode($request_data),
            'timeout' => 30,
            'sslverify' => true, // ACS uses proper SSL certificates
        ));
        
        // Check for HTTP errors
        if (is_wp_error($response)) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'ACS API request failed',
                'error_message' => $response->get_error_message(),
                'error_code' => $response->get_error_code(),
                'courier' => 'ACS',
                'acsalias' => $request_data['ACSAlias'] ?? '',
            ));
            return $response;
        }
        
        // Check HTTP status code
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $error_message = 'ACS API returned HTTP ' . $status_code;
            if ($status_code === 403) {
                $error_message = 'ACS API Key (AcsApiKey header) is invalid or missing (403 Forbidden)';
            } elseif ($status_code === 406) {
                $error_message = 'ACS API rate limit exceeded (406 Not Acceptable - max 10 calls/second)';
            }
            
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'ACS API HTTP error',
                'status_code' => $status_code,
                'error_message' => $error_message,
                'courier' => 'ACS',
                'acsalias' => $request_data['ACSAlias'] ?? '',
            ));
            
            return new WP_Error('acs_http_error', $error_message, array(
                'status_code' => $status_code,
            ));
        }
        
        // Parse JSON response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'ACS API JSON parse error',
                'json_error' => json_last_error_msg(),
                'response_body' => $body,
                'courier' => 'ACS',
                'acsalias' => $request_data['ACSAlias'] ?? '',
            ));
            
            return new WP_Error('acs_json_error', 'Failed to parse ACS API response: ' . json_last_error_msg());
        }
        
        // Check for execution errors at top level
        if (isset($data['ACSExecution_HasError']) && $data['ACSExecution_HasError'] === true) {
            $error_message = $data['ACSExecutionErrorMessage'] ?? 'Unknown error';
            
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'ACS API execution error',
                'error_message' => $error_message,
                'courier' => 'ACS',
                'acsalias' => $request_data['ACSAlias'] ?? '',
            ));
            
            return new WP_Error('acs_execution_error', 'ACS API error: ' . $error_message, array(
                'error_message' => $error_message,
            ));
        }
        
        // Extract output response
        // NOTE: API returns "ACSOutputResponce" (with typo), not "ACSOutputResponse"
        // The response structure is: ACSOutputResponce -> ACSTableOutput -> Table_Data
        // or: ACSOutputResponce -> ACSValueOutput -> [0] -> fields
        $output_response = $data['ACSOutputResponce'] ?? array();
        
        // Check for errors in ACSValueOutput (common error location)
        if (!empty($output_response['ACSValueOutput']) && is_array($output_response['ACSValueOutput'])) {
            // Check first element for error
            if (isset($output_response['ACSValueOutput'][0]['Error_Message']) && 
                !empty($output_response['ACSValueOutput'][0]['Error_Message'])) {
                $error_message = $output_response['ACSValueOutput'][0]['Error_Message'];
                
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'message' => 'ACS API response error',
                    'error_message' => $error_message,
                    'courier' => 'ACS',
                    'acsalias' => $request_data['ACSAlias'] ?? '',
                    'full_response' => $output_response, // Log full response for debugging
                ));
                
                // Provide user-friendly error message
                $user_message = $error_message;
                if (stripos($error_message, 'no acs shipment found') !== false || 
                    stripos($error_message, 'νo acs shipment found') !== false ||
                    stripos($error_message, 'no shipment found') !== false) {
                    $user_message = 'Voucher not found in ACS system. Please verify the voucher number or check if it belongs to your company account.';
                }
                
                return new WP_Error('acs_response_error', $user_message, array(
                    'error_message' => $error_message,
                    'original_error' => $error_message,
                ));
            }
            
            // Also check for error in other possible fields
            foreach ($output_response['ACSValueOutput'] as $value_output) {
                if (is_array($value_output)) {
                    // Check various possible error field names
                    $error_fields = array('Error_Message', 'Error', 'ErrorMessage', 'error_message', 'error');
                    foreach ($error_fields as $error_field) {
                        if (isset($value_output[$error_field]) && !empty($value_output[$error_field])) {
                            $error_message = $value_output[$error_field];
                            
                            Courier_Intelligence_Logger::log('voucher', 'error', array(
                                'message' => 'ACS API response error (alternative field)',
                                'error_message' => $error_message,
                                'error_field' => $error_field,
                                'courier' => 'ACS',
                                'acsalias' => $request_data['ACSAlias'] ?? '',
                            ));
                            
                            // Provide user-friendly error message
                            $user_message = $error_message;
                            if (stripos($error_message, 'no acs shipment found') !== false || 
                                stripos($error_message, 'νo acs shipment found') !== false ||
                                stripos($error_message, 'no shipment found') !== false) {
                                $user_message = 'Voucher not found in ACS system. Please verify the voucher number or check if it belongs to your company account.';
                            }
                            
                            return new WP_Error('acs_response_error', $user_message, array(
                                'error_message' => $error_message,
                                'original_error' => $error_message,
                            ));
                        }
                    }
                }
            }
        }
        
        // Check for errors in Table_Data structure (some APIs return errors here)
        if (isset($output_response['ACSTableOutput']['Table_Data']) && 
            is_array($output_response['ACSTableOutput']['Table_Data']) &&
            !empty($output_response['ACSTableOutput']['Table_Data'])) {
            // Check first row for error messages
            $first_row = $output_response['ACSTableOutput']['Table_Data'][0];
            if (is_array($first_row)) {
                $error_fields = array('Error_Message', 'Error', 'ErrorMessage', 'error_message', 'error', 'message');
                foreach ($error_fields as $error_field) {
                    if (isset($first_row[$error_field]) && !empty($first_row[$error_field])) {
                        $error_message = $first_row[$error_field];
                        
                        Courier_Intelligence_Logger::log('voucher', 'error', array(
                            'message' => 'ACS API response error (in Table_Data)',
                            'error_message' => $error_message,
                            'error_field' => $error_field,
                            'courier' => 'ACS',
                            'acsalias' => $request_data['ACSAlias'] ?? '',
                        ));
                        
                        // Provide user-friendly error message
                        $user_message = $error_message;
                        if (stripos($error_message, 'no acs shipment found') !== false || 
                            stripos($error_message, 'νo acs shipment found') !== false ||
                            stripos($error_message, 'no shipment found') !== false) {
                            $user_message = 'Voucher not found in ACS system. Please verify the voucher number or check if it belongs to your company account.';
                        }
                        
                        return new WP_Error('acs_response_error', $user_message, array(
                            'error_message' => $error_message,
                            'original_error' => $error_message,
                        ));
                    }
                }
            }
        }
        
        // Check for empty Table_Data (might indicate "not found" for tracking methods)
        if (isset($output_response['ACSTableOutput']['Table_Data']) && 
            (empty($output_response['ACSTableOutput']['Table_Data']) || 
             !is_array($output_response['ACSTableOutput']['Table_Data']))) {
            // For tracking methods, empty Table_Data likely means voucher not found
            if (isset($request_data['ACSAlias']) && 
                (strpos($request_data['ACSAlias'], 'Tracking') !== false)) {
                $error_message = 'Voucher not found in ACS system. Please verify the voucher number or check if it belongs to your company account.';
                
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'message' => 'ACS API: Empty tracking response (voucher not found)',
                    'courier' => 'ACS',
                    'acsalias' => $request_data['ACSAlias'] ?? '',
                ));
                
                return new WP_Error('acs_voucher_not_found', $error_message, array(
                    'error_message' => $error_message,
                ));
            }
        }
        
        return $output_response;
    }
    
    /**
     * Parse tracking response
     * 
     * Combines summary and details data into normalized format
     * 
     * @param array $summary_response Summary response from ACS_Trackingsummary
     * @param array $details_response Details response from ACS_TrackingDetails
     * @return array|WP_Error Tracking data
     */
    private function parse_tracking_response($summary_response, $details_response) {
        if (is_wp_error($summary_response)) {
            return $summary_response;
        }
        
        // Extract summary data from Table_Data
        $summary_data = array();
        if (isset($summary_response['ACSTableOutput']['Table_Data']) && 
            is_array($summary_response['ACSTableOutput']['Table_Data']) && 
            !empty($summary_response['ACSTableOutput']['Table_Data'])) {
            $summary_data = $summary_response['ACSTableOutput']['Table_Data'][0];
        }
        
        // Extract details data from Table_Data
        $events = array();
        if (!is_wp_error($details_response) && 
            isset($details_response['ACSTableOutput']['Table_Data']) && 
            is_array($details_response['ACSTableOutput']['Table_Data'])) {
            foreach ($details_response['ACSTableOutput']['Table_Data'] as $checkpoint) {
                if (!empty($checkpoint['checkpoint_action'])) {
                    // Parse datetime
                    $datetime = isset($checkpoint['checkpoint_date_time']) ? $checkpoint['checkpoint_date_time'] : '';
                    $date = '';
                    $time = '';
                    if (!empty($datetime)) {
                        // Parse ISO 8601 format: "2019-01-28T09:21:00"
                        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $datetime);
                        if ($dt === false) {
                            // Try with milliseconds: "2019-01-28T09:21:00.587"
                            $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.u', $datetime);
                        }
                        if ($dt !== false) {
                            $date = $dt->format('Y-m-d');
                            $time = $dt->format('H:i');
                        }
                    }
                    
                    $events[] = array(
                        'date' => $date,
                        'time' => $time,
                        'datetime' => $datetime,
                        'station' => $checkpoint['checkpoint_location'] ?? '',
                        'status_title' => $checkpoint['checkpoint_action'] ?? '',
                        'remarks' => $checkpoint['checkpoint_notes'] ?? '',
                    );
                }
            }
        }
        
        // Sort events by datetime (oldest first, then reverse to get newest first)
        usort($events, function($a, $b) {
            $time_a = strtotime($a['datetime'] ?? '1970-01-01');
            $time_b = strtotime($b['datetime'] ?? '1970-01-01');
            return $time_b - $time_a; // Newest first
        });
        
        // Extract delivery information from summary
        $is_delivered = isset($summary_data['delivery_flag']) && intval($summary_data['delivery_flag']) === 1;
        $is_returned = isset($summary_data['returned_flag']) && intval($summary_data['returned_flag']) === 1;
        
        $delivery_date = '';
        $delivery_time = '';
        $recipient_name = '';
        
        if ($is_delivered && isset($summary_data['delivery_date'])) {
            $delivery_datetime = $summary_data['delivery_date'];
            // Parse ISO 8601 format
            $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $delivery_datetime);
            if ($dt !== false) {
                $delivery_date = $dt->format('Y-m-d');
                $delivery_time = $dt->format('H:i');
            }
            
            // Extract recipient name from delivery_info if available
            if (isset($summary_data['delivery_info'])) {
                // Format: "Shipment has been delivered to destination. Date: 21/12/2018 Name: XXXXXX"
                $delivery_info = $summary_data['delivery_info'];
                if (preg_match('/Name:\s*(.+?)(?:\s|$)/i', $delivery_info, $matches)) {
                    $recipient_name = trim($matches[1]);
                }
            }
            
            // Fallback to consignee field
            if (empty($recipient_name) && isset($summary_data['consignee'])) {
                $recipient_name = $summary_data['consignee'];
            }
        }
        
        // Get current status (latest event or from summary)
        $current_status_title = '';
        if (!empty($events)) {
            $latest_event = reset($events); // First element is the most recent
            $current_status_title = $latest_event['status_title'] ?? '';
        } elseif (isset($summary_data['delivery_info'])) {
            $current_status_title = $summary_data['delivery_info'];
        }
        
        // Map ACS status to normalized status
        $shipment_status = isset($summary_data['shipment_status']) ? intval($summary_data['shipment_status']) : 0;
        $current_status = $this->map_acs_status($is_delivered, $is_returned, $shipment_status, $current_status_title);
        
        $tracking_data = array(
            'success' => true,
            'delivered' => $is_delivered,
            'returned' => $is_returned,
            'status' => $current_status,
            'status_title' => $current_status_title,
            'events' => $events,
            'status_counter' => count($events),
            'voucher_code' => $summary_data['voucher_no'] ?? null,
            'reference_number' => null, // ACS doesn't return reference in tracking
        );
        
        // Delivery information
        if ($is_delivered) {
            $tracking_data['delivery_date'] = $delivery_date;
            $tracking_data['delivery_time'] = $delivery_time;
            $tracking_data['recipient_name'] = $recipient_name;
        }
        
        // Additional summary information
        if (!empty($summary_data)) {
            $tracking_data['pickup_date'] = isset($summary_data['pickup_date']) ? $summary_data['pickup_date'] : null;
            $tracking_data['sender'] = isset($summary_data['sender']) ? $summary_data['sender'] : null;
            $tracking_data['recipient'] = isset($summary_data['recipient']) ? $summary_data['recipient'] : null;
            $tracking_data['recipient_address'] = isset($summary_data['recipient_address']) ? $summary_data['recipient_address'] : null;
            $tracking_data['non_delivery_reason_code'] = isset($summary_data['non_delivery_reason_code']) ? $summary_data['non_delivery_reason_code'] : null;
            $tracking_data['shipment_status'] = $shipment_status;
        }
        
        // Include raw responses for debugging/advanced use
        $tracking_data['raw_response'] = array(
            'summary' => $summary_response,
            'details' => is_wp_error($details_response) ? null : $details_response,
        );
        
        return $tracking_data;
    }
    
    /**
     * Map ACS Courier status to normalized status
     * 
     * ACS uses status codes and flags:
     * - delivery_flag: 1 = delivered, 0 = undelivered
     * - returned_flag: 1 = returned
     * - shipment_status: 1-5 (see documentation)
     * 
     * Status codes:
     * - 1: Delivery Refusal (AP1, AP2, AP3)
     * - 2: Address Issues (LS1, LS3)
     * - 3: Consignee's absence (AS1)
     * - 4: Delivered
     * - 5: Various non-delivery reasons (AD1, AD3, AD8, DD1, EA1, PA2, PA4)
     * 
     * @param bool $is_delivered delivery_flag === 1
     * @param bool $is_returned returned_flag === 1
     * @param int $shipment_status Status code (1-5)
     * @param string $status_title Current status title/action
     * @return string Normalized status: created, in_transit, delivered, returned, issue
     */
    private function map_acs_status($is_delivered, $is_returned, $shipment_status, $status_title = '') {
        // If delivered, it's definitely delivered
        if ($is_delivered) {
            return 'delivered';
        }
        
        // If returned, it's returned
        if ($is_returned) {
            return 'returned';
        }
        
        // Map by status code
        switch ($shipment_status) {
            case 4:
                // Status 4 = delivered (but check flag to be sure)
                return $is_delivered ? 'delivered' : 'in_transit';
                
            case 1:
                // Delivery Refusal (AP1, AP2, AP3)
                return 'issue';
                
            case 2:
                // Address Issues (LS1, LS3)
                return 'issue';
                
            case 3:
                // Consignee's absence (AS1)
                return 'issue';
                
            case 5:
                // Various non-delivery reasons (AD1, AD3, AD8, DD1, EA1, PA2, PA4)
                // Some are in transit (DD1, EA1, PA2, PA4), some are issues
                $title_upper = mb_strtoupper($status_title, 'UTF-8');
                if (strpos($title_upper, 'ON THE WAY') !== false || 
                    strpos($title_upper, 'RESCHEDULE') !== false ||
                    strpos($title_upper, 'REDIRECT') !== false ||
                    strpos($title_upper, 'CHANGE OF DELIVERY DATE') !== false) {
                    return 'in_transit';
                }
                return 'issue';
        }
        
        // Map by status title keywords
        $title_upper = mb_strtoupper($status_title, 'UTF-8');
        
        // Delivered keywords
        if (strpos($title_upper, 'DELIVERY TO CONSIGNEE') !== false ||
            strpos($title_upper, 'DELIVERED') !== false) {
            return 'delivered';
        }
        
        // Returned keywords
        if (strpos($title_upper, 'RETURN') !== false ||
            strpos($title_upper, 'RETURNED') !== false) {
            return 'returned';
        }
        
        // Issue keywords
        if (strpos($title_upper, 'REFUSAL') !== false ||
            strpos($title_upper, 'ABSENCE') !== false ||
            strpos($title_upper, 'ADDRESS') !== false ||
            strpos($title_upper, 'PROBLEM') !== false ||
            strpos($title_upper, 'REJECTED') !== false) {
            return 'issue';
        }
        
        // In transit keywords
        if (strpos($title_upper, 'ARRIVAL') !== false ||
            strpos($title_upper, 'DEPARTURE') !== false ||
            strpos($title_upper, 'ON DELIVERY') !== false ||
            strpos($title_upper, 'HUB') !== false ||
            strpos($title_upper, 'TO DESTINATION') !== false) {
            return 'in_transit';
        }
        
        // Fallback: if we have a status title but don't recognize it, assume in_transit
        if (!empty($status_title)) {
            return 'in_transit';
        }
        
        // No status information at all
        return 'unknown';
    }
}

