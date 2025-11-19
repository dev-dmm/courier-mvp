<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Elta Courier Web Services API Client
 * Based on Elta Courier Web Services Integration Manual v1.2
 * 
 * WSDL Files:
 * - ELTACOURIERPOSTSIDETA.WSDL - Voucher Production (POST)
 * - PELTT03.WSDL - Shipping Status (Track & Trace)
 * - PELB64VG.WSDL - Printing Label
 * - GETPUDODETAILS.WSDL - PUDO Stations
 */
class Courier_Intelligence_Elta_API_Client {
    
    private $wsdl_base_url;
    private $user_code;      // PEL-USER-CODE (7 digits)
    private $user_pass;      // PEL-USER-PASS
    private $apost_code;    // PEL-APOST-CODE (Sender code, Master)
    private $apost_sub_code; // PEL-APOST-SUB-CODE (Sub Code, optional)
    private $user_lang;      // PEL-USER-LANG (Display Language)
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
        $this->apost_sub_code = $elta_settings['apost_sub_code'] ?? '';
        $this->user_lang = $elta_settings['user_lang'] ?? '';
        
        // Test mode flag
        $this->test_mode = isset($elta_settings['test_mode']) && $elta_settings['test_mode'] === 'yes';
        
        if ($this->test_mode) {
            // Use test endpoint if available
            $this->wsdl_base_url = $elta_settings['test_endpoint'] ?? $this->wsdl_base_url;
        }
    }
    
    /**
     * Create a shipping voucher/shipment
     * WSDL: CREATEAWB02.WSDL
     * Method: READ
     * 
     * This is the PRIMARY method for creating new Elta vouchers.
     * It returns VG_CODE, RETURN_VG, EPITAGH_VG, VG_CHILD, etc.
     * 
     * @param array $shipment_data Shipment details
     * @return array|WP_Error Response data or error
     */
    public function create_voucher($shipment_data) {
        if (empty($this->user_code) || empty($this->user_pass) || empty($this->apost_code)) {
            return new WP_Error('missing_credentials', 'Elta API credentials not configured (User Code, Password, and Sender Code required)');
        }
        
        // Validate PUDO station if service type is 7
        if (isset($shipment_data['service_type']) && $shipment_data['service_type'] === '7') {
            if (empty($shipment_data['pudo_station'])) {
                return new WP_Error('missing_pudo_station', 'PUDO Station Code is required when PEL-SERVICE is set to 7 (PUDO)');
            }
        }
        
        // WSDL file for voucher creation
        $wsdl_path = $this->get_wsdl_path('CREATEAWB02.WSDL');
        
        // Prepare SOAP request data
        $soap_data = $this->prepare_voucher_soap_request($shipment_data);
        
        // Make SOAP request
        $response = $this->make_soap_request($wsdl_path, 'READ', $soap_data);
        
        if (is_wp_error($response)) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $shipment_data['order_id'] ?? null,
                'message' => 'Failed to create Elta voucher',
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
                'courier' => 'Elta',
            ));
            return $response;
        }
        
        // Parse response
        $st_flag = isset($response['ST-FLAG']) ? intval($response['ST-FLAG']) : -1;
        
        if ($st_flag === 0) {
            // Success - extract voucher number
            $voucher_number = isset($response['VG_CODE']) ? $response['VG_CODE'] : null;
            
            if ($voucher_number) {
                Courier_Intelligence_Logger::log('voucher', 'success', array(
                    'external_order_id' => $shipment_data['order_id'] ?? null,
                    'message' => 'Elta voucher created successfully',
                    'voucher_number' => $voucher_number,
                    'courier' => 'Elta',
                ));
                
                return array(
                    'success' => true,
                    'voucher_number' => $voucher_number,
                    'return_voucher' => $response['RETURN_VG'] ?? null,
                    'check_return_voucher' => $response['EPITAGH_VG'] ?? null,
                    'child_vouchers' => $response['VG_CHILD'] ?? array(),
                    'response' => $response,
                );
            } else {
                return new WP_Error('no_voucher_number', 'Voucher created but no voucher number in response', $response);
            }
        } else {
            // Error
            $error_title = isset($response['ST-TITLE']) ? $response['ST-TITLE'] : 'Unknown error';
            $error_message = sprintf('Elta API Error (ST-FLAG: %d): %s', $st_flag, $error_title);
            
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => $shipment_data['order_id'] ?? null,
                'message' => $error_message,
                'st_flag' => $st_flag,
                'st_title' => $error_title,
                'courier' => 'Elta',
            ));
            
            return new WP_Error('elta_api_error', $error_message, array(
                'st_flag' => $st_flag,
                'st_title' => $error_title,
                'response' => $response,
            ));
        }
    }
    
    /**
     * Post voucher details to an existing voucher
     * WSDL: ELTACOURIERPOSTSIDETA.WSDL
     * Method: READ
     * 
     * This is used when you already have a VG_CODE (e.g., from another system)
     * and want to post additional details to it.
     * 
     * @param array $shipment_data Shipment details (must include existing_voucher_code)
     * @return array|WP_Error Response data or error
     */
    public function post_voucher_details($shipment_data) {
        if (empty($this->user_code) || empty($this->user_pass) || empty($this->apost_code)) {
            return new WP_Error('missing_credentials', 'Elta API credentials not configured');
        }
        
        if (empty($shipment_data['existing_voucher_code'])) {
            return new WP_Error('missing_voucher_code', 'existing_voucher_code is required for posting voucher details');
        }
        
        // WSDL file for posting voucher details
        $wsdl_path = $this->get_wsdl_path('ELTACOURIERPOSTSIDETA.WSDL');
        
        // Prepare SOAP request data (this includes VG_CODE in sideta_numbers)
        $soap_data = $this->prepare_voucher_soap_request($shipment_data);
        
        // Make SOAP request
        $response = $this->make_soap_request($wsdl_path, 'READ', $soap_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $st_flag = isset($response['ST-FLAG']) ? intval($response['ST-FLAG']) : -1;
        
        if ($st_flag === 0) {
            return array(
                'success' => true,
                'response' => $response,
            );
        } else {
            $error_title = isset($response['ST-TITLE']) ? $response['ST-TITLE'] : 'Unknown error';
            return new WP_Error('elta_api_error', sprintf('Elta API Error (ST-FLAG: %d): %s', $st_flag, $error_title), array(
                'st_flag' => $st_flag,
                'st_title' => $error_title,
            ));
        }
    }
    
    /**
     * Get WSDL file path
     * Tries local file first, then falls back to URL
     * 
     * @param string $wsdl_filename WSDL filename
     * @return string WSDL path or URL
     */
    private function get_wsdl_path($wsdl_filename) {
        // Try local WSDL file first (recommended approach)
        $local_wsdl = COURIER_INTELLIGENCE_PLUGIN_DIR . 'wsdl/' . $wsdl_filename;
        if (file_exists($local_wsdl)) {
            return $local_wsdl;
        }
        
        // Fallback to URL (may not work, but allows testing)
        return $this->wsdl_base_url . '/' . $wsdl_filename;
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
        
        // WSDL file for tracking
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
     * Prepare voucher SOAP request data according to Elta API format
     * 
     * @param array $shipment_data
     * @return array
     */
    private function prepare_voucher_soap_request($shipment_data) {
        // User details record
        $user_details = array(
            'PEL-USER-CODE' => str_pad($this->user_code, 7, '0', STR_PAD_LEFT), // Must be 7 digits
            'PEL-USER-PASS' => $this->user_pass,
            'PEL-APOST-CODE' => $this->apost_code,
        );
        
        if (!empty($this->apost_sub_code)) {
            $user_details['PEL-APOST-SUB-CODE'] = $this->apost_sub_code;
        }
        
        if (!empty($this->user_lang)) {
            $user_details['PEL-USER-LANG'] = $this->user_lang;
        }
        
        // Pel details record
        $pel_details = array(
            'PEL-PARAL-NAME' => substr($shipment_data['recipient_name'] ?? '', 0, 150),
            'PEL-PARAL-ADDRESS' => substr($shipment_data['recipient_address'] ?? '', 0, 150),
            'PEL-PARAL-AREA' => substr($shipment_data['recipient_city'] ?? '', 0, 40),
            'PEL-PARAL-TK' => substr($shipment_data['recipient_postcode'] ?? '', 0, 5),
            'PEL-PARAL-THL-1' => substr($shipment_data['recipient_phone'] ?? '', 0, 10),
            'PEL-PARAL-THL-2' => substr($shipment_data['recipient_mobile'] ?? '', 0, 10),
        );
        
        // Service type: 1=Delivery to Recipient, 2=Receipt from Local Offices, 7=PUDO, 8=CU
        $pel_details['PEL-SERVICE'] = $shipment_data['service_type'] ?? '1';
        
        // Weight (format: 999999.999, e.g. 000012.123)
        if (isset($shipment_data['weight'])) {
            $weight = number_format(floatval($shipment_data['weight']), 3, '.', '');
            $pel_details['PEL-BAROS'] = str_pad($weight, 10, '0', STR_PAD_LEFT);
        }
        
        // Number of packages (max 150)
        if (isset($shipment_data['pieces'])) {
            $pieces = min(intval($shipment_data['pieces']), 150);
            $pel_details['PEL-TEMAXIA'] = str_pad($pieces, 3, '0', STR_PAD_LEFT);
        }
        
        // Comments
        if (isset($shipment_data['notes'])) {
            $pel_details['PEL-PARAL-SXOLIA'] = substr($shipment_data['notes'], 0, 100);
        }
        
        // Special management flags
        $pel_details['PEL-SUR-1'] = isset($shipment_data['special_management']) && $shipment_data['special_management'] ? '1' : '0';
        $pel_details['PEL-SUR-2'] = isset($shipment_data['determined_time']) && $shipment_data['determined_time'] ? '1' : '0';
        $pel_details['PEL-SUR-3'] = isset($shipment_data['saturday_delivery']) && $shipment_data['saturday_delivery'] ? '1' : '0';
        
        // Cash on Delivery vs Cheque on Delivery
        // Manual says: "Allow only cash or only check"
        $cod_amount = isset($shipment_data['cod_amount']) ? floatval($shipment_data['cod_amount']) : 0;
        $has_cheques = false;
        
        // Check if any cheques are provided
        for ($i = 1; $i <= 4; $i++) {
            $key = 'cheque_amount_' . $i;
            if (isset($shipment_data[$key]) && floatval($shipment_data[$key]) > 0) {
                $has_cheques = true;
                break;
            }
        }
        
        if ($cod_amount > 0 && $has_cheques) {
            // Both COD and cheques provided - invalid per manual
            // Prefer COD over cheques
            $has_cheques = false;
        }
        
        // Cash on Delivery (format: 9999999.99)
        if ($cod_amount > 0 && !$has_cheques) {
            $cod_formatted = number_format($cod_amount, 2, '.', '');
            $pel_details['PEL-ANT-POSO'] = str_pad($cod_formatted, 10, '0', STR_PAD_LEFT);
        }
        
        // Cheque on delivery (up to 4 cheques) - only if no COD
        if ($has_cheques && $cod_amount == 0) {
            for ($i = 1; $i <= 4; $i++) {
                $key = 'cheque_amount_' . $i;
                if (isset($shipment_data[$key]) && floatval($shipment_data[$key]) > 0) {
                    $cheque_amount = number_format(floatval($shipment_data[$key]), 2, '.', '');
                    $pel_details['PEL-ANT-POSO' . $i] = str_pad($cheque_amount, 10, '0', STR_PAD_LEFT);
                    
                    // Cheque expiration date (dd/mm/yyyy)
                    $date_key = 'cheque_date_' . $i;
                    if (isset($shipment_data[$date_key])) {
                        $pel_details['PEL-ANT-DATE' . $i] = $shipment_data[$date_key];
                    }
                }
            }
        }
        
        // Shipping insurance
        if (isset($shipment_data['insurance_amount']) && floatval($shipment_data['insurance_amount']) > 0) {
            $insurance = number_format(floatval($shipment_data['insurance_amount']), 2, '.', '');
            $pel_details['PEL-ASF-POSO'] = str_pad($insurance, 10, '0', STR_PAD_LEFT);
        }
        
        // Reference number (alphanumeric, up to 30 characters)
        if (isset($shipment_data['reference_number'])) {
            $pel_details['PEL-REF-NO'] = substr($shipment_data['reference_number'], 0, 30);
        } elseif (isset($shipment_data['order_id'])) {
            $pel_details['PEL-REF-NO'] = substr((string) $shipment_data['order_id'], 0, 30);
        }
        
        // SIDETA-EIDOS: 1=Documents, 2=Parcel
        $pel_details['SIDETA-EIDOS'] = isset($shipment_data['shipment_type']) && $shipment_data['shipment_type'] === 'documents' ? '1' : '2';
        
        // PUDO Station Code (required if PEL-SERVICE = 7)
        // Note: Validation should happen in calling method (create_voucher)
        // This method just prepares the data
        if ($pel_details['PEL-SERVICE'] === '7' && !empty($shipment_data['pudo_station'])) {
            $pel_details['PUDO-STATION'] = substr($shipment_data['pudo_station'], 0, 5);
        }
        
        // Sideta numbers (for existing vouchers or child vouchers)
        $sideta_numbers = array();
        if (isset($shipment_data['existing_voucher_code'])) {
            $sideta_numbers['VG_CODE'] = substr($shipment_data['existing_voucher_code'], 0, 13);
        }
        
        // Build complete request structure
        // The manual mentions "Record – user_details", "Record – pel_details", "Record – sideta_numbers"
        // This suggests a nested structure, which is more likely to match the WSDL
        // If the WSDL requires a flat structure, this can be adjusted after testing
        
        $request = array(
            'user_details' => $user_details,
            'pel_details' => $pel_details,
        );
        
        // Add sideta_numbers only if present (for POST service or child vouchers)
        if (!empty($sideta_numbers)) {
            $request['sideta_numbers'] = $sideta_numbers;
        }
        
        return $request;
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
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => 'Elta SOAP request failed',
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'fault_string' => $e->faultstring ?? '',
                'fault_code' => $e->faultcode ?? '',
                'wsdl_path' => $wsdl_path,
                'courier' => 'Elta',
            ));
            
            return new WP_Error('soap_fault', 'Elta SOAP request failed: ' . $e->getMessage(), array(
                'fault_code' => $e->faultcode ?? '',
                'fault_string' => $e->faultstring ?? '',
                'wsdl_path' => $wsdl_path,
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
     * Get voucher label PDF
     * WSDL: PELB64VG.WSDL
     * Method: READ
     * 
     * @param string $voucher_code 13-digit voucher code
     * @param string $paper_size 'A4' or 'A6' (default: 'A6')
     * @return array|WP_Error PDF data in base64 or error
     */
    public function get_voucher_label($voucher_code, $paper_size = 'A6') {
        if (empty($this->user_code) || empty($this->user_pass) || empty($this->apost_code)) {
            return new WP_Error('missing_credentials', 'Elta API credentials not configured');
        }
        
        // WSDL file for printing
        $wsdl_path = $this->get_wsdl_path('PELB64VG.WSDL');
        
        // Prepare sender code (if subcode exists: MASTER+6SPACES+CHILD)
        $sender_code = $this->apost_code;
        if (!empty($this->apost_sub_code)) {
            $sender_code = str_pad($this->apost_code, 6, ' ') . $this->apost_sub_code;
        }
        
        // Paper size: 0=A4, 1=A6
        $paper_size_code = $paper_size === 'A4' ? '0' : '1';
        
        // Prepare SOAP request
        $soap_data = array(
            'PEL_USER_CODE' => $this->user_code,
            'PEL_USER_PASS' => $this->user_pass,
            'PEL_APOST_CODE' => $sender_code,
            'VG_CODE' => substr($voucher_code, 0, 13),
            'PAPER_SIZE' => $paper_size_code,
        );
        
        // Make SOAP request
        $response = $this->make_soap_request($wsdl_path, 'READ', $soap_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $st_flag = isset($response['ST-FLAG']) ? intval($response['ST-FLAG']) : -1;
        
        if ($st_flag === 0 && isset($response['B64_STRING'])) {
            return array(
                'success' => true,
                'pdf_base64' => $response['B64_STRING'],
                'paper_size' => $paper_size,
            );
        } else {
            $error_title = isset($response['ST-TITLE']) ? $response['ST-TITLE'] : 'Unknown error';
            return new WP_Error('print_error', 'Failed to get voucher label: ' . $error_title, array(
                'st_flag' => $st_flag,
                'st_title' => $error_title,
            ));
        }
    }
    
    /**
     * Get PUDO Stations
     * WSDL: GETPUDODETAILS.WSDL
     * Method: READ
     * 
     * @return array|WP_Error PUDO stations data or error
     */
    public function get_pudo_stations() {
        if (empty($this->user_code) || empty($this->user_pass) || empty($this->apost_code)) {
            return new WP_Error('missing_credentials', 'Elta API credentials not configured');
        }
        
        // WSDL file for PUDO stations
        $wsdl_path = $this->get_wsdl_path('GETPUDODETAILS.WSDL');
        
        // Prepare SOAP request
        $soap_data = array(
            'PEL_USER_CODE' => $this->user_code,
            'PEL_USER_PASS' => $this->user_pass,
            'PEL_APOST_CODE' => $this->apost_code,
        );
        
        // Make SOAP request
        $response = $this->make_soap_request($wsdl_path, 'READ', $soap_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $st_flag = isset($response['ST-FLAG']) ? intval($response['ST-FLAG']) : -1;
        
        if ($st_flag === 0) {
            return array(
                'success' => true,
                'stations' => $response, // Adjust based on actual response structure
            );
        } else {
            $error_title = isset($response['ST-TITLE']) ? $response['ST-TITLE'] : 'Unknown error';
            return new WP_Error('pudo_error', 'Failed to get PUDO stations: ' . $error_title, array(
                'st_flag' => $st_flag,
                'st_title' => $error_title,
            ));
        }
    }
    
    /**
     * Parse tracking response
     * 
     * @param array $response API response
     * @return array Tracking data
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
    
    /**
     * Get voucher status and delivery information
     * Convenience method that wraps track_shipment for easier use
     * 
     * @param string $voucher_code Voucher number (13 digits)
     * @return array|WP_Error Status and delivery information
     */
    public function get_voucher_status($voucher_code) {
        return $this->track_shipment($voucher_code, 'voucher');
    }
    
    /**
     * Validate API credentials by making a test tracking call
     * 
     * @return bool|WP_Error
     */
    public function validate_credentials() {
        if (empty($this->user_code) || empty($this->user_pass) || empty($this->apost_code)) {
            return new WP_Error('missing_credentials', 'Elta API credentials not configured (User Code, Password, and Sender Code required)');
        }
        
        // Try to get PUDO stations as a validation test (lightweight call)
        $result = $this->get_pudo_stations();
        
        if (is_wp_error($result)) {
            // Check if it's a credential error
            $error_data = $result->get_error_data();
            if (isset($error_data['st_flag']) && in_array($error_data['st_flag'], array(1, 2, 3))) {
                return new WP_Error('invalid_credentials', 'Elta API credentials are invalid');
            }
            return $result;
        }
        
        return true;
    }
}

