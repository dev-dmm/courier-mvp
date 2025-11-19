<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elta Courier Tracking API Client
 * 
 * Minimal implementation for tracking existing vouchers only.
 * 
 * IMPORTANT: This class uses SOAP, which requires a WSDL file.
 * 
 * Why WSDL files are needed:
 * - Elta's API is SOAP-based, not REST
 * - PHP's SoapClient requires a WSDL file to understand:
 *   - The SOAP endpoint URL
 *   - Method names (e.g., "READ")
 *   - Request/response field names (e.g., WPEL_CODE, WPEL_USER, WPEL_VG)
 *   - Data types and structure
 * - Elta provides WSDL files via FTP (not publicly hosted)
 * 
 * Required WSDL File:
 * - PELTT03.WSDL - Shipping Status (Track & Trace) - REQUIRED
 *   Download from Elta FTP and place in wsdl/ folder
 * 
 * Usage:
 *   $client = new Courier_Intelligence_Elta_API_Client();
 *   $result = $client->get_voucher_status('VP1234567890123');
 * 
 * This class only READS tracking information for existing vouchers.
 * It does NOT create vouchers (vouchers come from order meta keys via other plugins).
 */
class Courier_Intelligence_Elta_API_Client {
    
    private $wsdl_base_url;
    private $user_code;      // PEL-USER-CODE (7 digits)
    private $user_pass;      // PEL-USER-PASS
    private $apost_code;    // PEL-APOST-CODE (Sender code, Master)
    private $test_mode;
    
    /**
     * Constructor
     */
    public function __construct($courier_settings = array()) {
        // Get Elta-specific settings
        $settings = get_option('courier_intelligence_settings', array());
        $elta_settings = $settings['couriers']['elta'] ?? array();
        
        // Merge with provided settings
        $elta_settings = array_merge($elta_settings, $courier_settings);
        
        // Set WSDL base URL (default to test endpoint)
        // Test: https://wsstage.elta-courier.gr
        // Production: https://customers.elta-courier.gr
        $this->wsdl_base_url = $elta_settings['api_endpoint'] ?? 'https://wsstage.elta-courier.gr';
        
        // Elta authentication credentials
        $this->user_code = $elta_settings['user_code'] ?? $elta_settings['username'] ?? '';
        $this->user_pass = $elta_settings['user_pass'] ?? $elta_settings['password'] ?? '';
        $this->apost_code = $elta_settings['apost_code'] ?? $elta_settings['sender_code'] ?? '';
        
        // Test mode flag
        $this->test_mode = isset($elta_settings['test_mode']) && $elta_settings['test_mode'] === 'yes';
        
        if ($this->test_mode) {
            // Use test endpoint if available
            $this->wsdl_base_url = $elta_settings['test_endpoint'] ?? $this->wsdl_base_url;
        }
    }
    
    /**
     * Get voucher status and delivery information
     * 
     * This is the main method you'll use. It takes a voucher number and returns
     * all available tracking information including status, events, and delivery details.
     * 
     * @param string $voucher_code Voucher number (13 digits)
     * @return array|WP_Error Status and delivery information
     * 
     * Example response:
     *   array(
     *     'success' => true,
     *     'delivered' => true,
     *     'status' => 'delivered',
     *     'status_title' => 'Delivered',
     *     'delivery_date' => 'DD/MM/YYYY',
     *     'delivery_time' => 'HH:MM',
     *     'recipient_name' => 'Name',
     *     'events' => array(...),
     *     'raw_response' => array(...)
     *   )
     */
    public function get_voucher_status($voucher_code) {
        return $this->track_shipment($voucher_code, 'voucher');
    }
    
    /**
     * Track a shipment
     * WSDL: PELTT03.WSDL
     * Method: READ
     * 
     * @param string $tracking_number Tracking/voucher number or reference number
     * @param string $search_type 'voucher' or 'reference' (default: 'voucher')
     * @return array|WP_Error Tracking data or error
     */
    public function track_shipment($tracking_number, $search_type = 'voucher') {
        if (empty($this->user_code) || empty($this->user_pass) || empty($this->apost_code)) {
            return new WP_Error('missing_credentials', 'Elta API credentials not configured');
        }
        
        // WSDL file for tracking - REQUIRED
        $wsdl_path = $this->get_wsdl_path('PELTT03.WSDL');
        
        // Prepare SOAP request
        $soap_data = array(
            'WPEL_CODE' => $this->apost_code,
            'WPEL_USER' => $this->user_code,
            'WPEL_PASS' => $this->user_pass,
            'WPEL_VG' => $search_type === 'voucher' ? $tracking_number : '',
            'WPEL_REF' => $search_type === 'reference' ? $tracking_number : '',
            'WPEL_FLAG' => $search_type === 'voucher' ? '1' : '2',
        );
        
        // Make SOAP request
        $response = $this->make_soap_request($wsdl_path, 'READ', $soap_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $this->parse_tracking_response($response);
    }
    
    /**
     * Get WSDL file path
     * 
     * IMPORTANT: Elta's API uses SOAP, which requires a WSDL file to define:
     * - The SOAP endpoint URL
     * - Method names (e.g., "READ")
     * - Request/response field names (e.g., WPEL_CODE, WPEL_USER, WPEL_VG)
     * - Data types and structure
     * 
     * PHP's SoapClient cannot work without a valid WSDL. Elta provides WSDL files
     * via FTP - download them and place in the wsdl/ folder.
     * 
     * This method tries local file first (recommended), then falls back to URL
     * (which typically won't work as Elta doesn't host WSDL files publicly).
     * 
     * @param string $wsdl_filename WSDL filename (e.g., 'PELTT03.WSDL')
     * @return string WSDL path/URL
     */
    private function get_wsdl_path($wsdl_filename) {
        // Try local WSDL file first (recommended approach)
        $local_wsdl = COURIER_INTELLIGENCE_PLUGIN_DIR . 'wsdl/' . $wsdl_filename;
        if (file_exists($local_wsdl)) {
            return $local_wsdl;
        }
        
        // Fallback to URL (may not work - Elta doesn't host WSDL files publicly)
        // This will likely result in a SoapFault, but allows the error to be caught
        // and displayed to the user with instructions to download WSDL files
        $wsdl_url = $this->wsdl_base_url . '/' . $wsdl_filename;
        
        // Log warning that local file is missing
        Courier_Intelligence_Logger::log('voucher', 'warning', array(
            'message' => 'WSDL file not found locally, attempting URL fallback (may fail)',
            'wsdl_filename' => $wsdl_filename,
            'local_path' => $local_wsdl,
            'fallback_url' => $wsdl_url,
            'courier' => 'Elta',
        ));
        
        return $wsdl_url;
    }
    
    /**
     * Make SOAP request to Elta Web Services
     * 
     * @param string $wsdl_path WSDL file path (local file or URL)
     * @param string $method SOAP method name
     * @param array $data Request data
     * @return array|WP_Error
     */
    private function make_soap_request($wsdl_path, $method, $data) {
        // Check if SOAP extension is available
        if (!class_exists('SoapClient')) {
            return new WP_Error('soap_not_available', 'SOAP extension is not available. Please enable PHP SOAP extension.');
        }
        
        try {
            // Create SOAP client
            $soap_options = array(
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE, // Don't cache WSDL for development
            );
            
            // If using URL, add location option
            if (filter_var($wsdl_path, FILTER_VALIDATE_URL)) {
                // Extract base URL for location
                $wsdl_dir = dirname($wsdl_path);
                $soap_options['location'] = $wsdl_dir; // SOAP endpoint location
            }
            
            $client = new SoapClient($wsdl_path, $soap_options);
            
            // Call SOAP method
            $result = $client->$method($data);
            
            // Convert SOAP result to array
            $response = json_decode(json_encode($result), true);
            
            return $response;
            
        } catch (SoapFault $e) {
            // Check if error is related to WSDL parsing (missing/invalid WSDL file)
            $is_wsdl_error = (
                stripos($e->getMessage(), 'WSDL') !== false ||
                stripos($e->getMessage(), 'parsing') !== false ||
                stripos($e->getMessage(), 'not found') !== false ||
                stripos($e->getMessage(), 'failed to load') !== false
            );
            
            $error_message = 'Elta SOAP request failed: ' . $e->getMessage();
            
            // Add helpful message if WSDL file is missing
            if ($is_wsdl_error && !file_exists($wsdl_path) && !filter_var($wsdl_path, FILTER_VALIDATE_URL)) {
                $wsdl_filename = basename($wsdl_path);
                $error_message .= sprintf(
                    ' The WSDL file "%s" is missing. Please download it from Elta\'s FTP and place it in the %s folder.',
                    $wsdl_filename,
                    COURIER_INTELLIGENCE_PLUGIN_DIR . 'wsdl/'
                );
            }
            
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Elta SOAP request failed',
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'fault_string' => $e->faultstring ?? '',
                'fault_code' => $e->faultcode ?? '',
                'wsdl_path' => $wsdl_path,
                'is_wsdl_error' => $is_wsdl_error,
                'courier' => 'Elta',
            ));
            
            return new WP_Error('soap_fault', $error_message, array(
                'fault_code' => $e->faultcode ?? '',
                'fault_string' => $e->faultstring ?? '',
                'wsdl_path' => $wsdl_path,
                'is_wsdl_error' => $is_wsdl_error,
            ));
        } catch (Exception $e) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Elta API request exception',
                'error_message' => $e->getMessage(),
                'wsdl_path' => $wsdl_path,
                'courier' => 'Elta',
            ));
            
            return new WP_Error('api_exception', 'Elta API request exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Parse tracking response
     * 
     * @param array $response API response
     * @return array|WP_Error Tracking data
     */
    private function parse_tracking_response($response) {
        if (is_wp_error($response)) {
            return $response;
        }
        
        $st_flag = isset($response['ST-FLAG']) ? intval($response['ST-FLAG']) : -1;
        
        if ($st_flag !== 0) {
            $error_title = isset($response['ST-TITLE']) ? $response['ST-TITLE'] : 'Unknown error';
            return new WP_Error('tracking_error', 'Tracking failed: ' . $error_title, array(
                'st_flag' => $st_flag,
                'st_title' => $error_title,
            ));
        }
        
        // Parse tracking events
        $events = array();
        $status_counter = isset($response['WEB_STATUS_COUNTER']) ? intval($response['WEB_STATUS_COUNTER']) : 0;
        
        // Extract events (response may contain multiple events)
        for ($i = 0; $i < $status_counter; $i++) {
            $event = array(
                'date' => isset($response['WEB_DATE'][$i]) ? $response['WEB_DATE'][$i] : '',
                'time' => isset($response['WEB_TIME'][$i]) ? $response['WEB_TIME'][$i] : '',
                'station' => isset($response['WEB_STATION'][$i]) ? $response['WEB_STATION'][$i] : '',
                'status_title' => isset($response['WEB_STATUS_TITLE'][$i]) ? $response['WEB_STATUS_TITLE'][$i] : '',
                'remarks' => isset($response['WEB_REMARKS'][$i]) ? $response['WEB_REMARKS'][$i] : '',
            );
            $events[] = $event;
        }
        
        // Check if delivered
        $is_delivered = isset($response['POD_DATE']) && !empty($response['POD_DATE']);
        
        // Get current status (latest event)
        $current_status = '';
        $current_status_title = '';
        if (!empty($events)) {
            $latest_event = end($events);
            $current_status_title = $latest_event['status_title'] ?? '';
        }
        
        // Determine status based on delivery and events
        if ($is_delivered) {
            $current_status = 'delivered';
        } elseif (!empty($events)) {
            $current_status = 'in_transit';
        } else {
            $current_status = 'unknown';
        }
        
        $tracking_data = array(
            'success' => true,
            'delivered' => $is_delivered,
            'status' => $current_status,
            'status_title' => $current_status_title,
            'events' => $events,
            'status_counter' => $status_counter,
            'voucher_code' => $response['VG_CODE'] ?? null,
            'reference_number' => $response['REF_NO'] ?? null,
        );
        
        // Delivery information (POD = Proof of Delivery)
        if ($is_delivered) {
            $tracking_data['delivery_date'] = $response['POD_DATE'] ?? '';
            $tracking_data['delivery_time'] = $response['POD_TIME'] ?? '';
            $tracking_data['recipient_name'] = $response['POD_NAME'] ?? '';
        }
        
        // Include raw response for debugging/advanced use
        $tracking_data['raw_response'] = $response;
        
        return $tracking_data;
    }
}
