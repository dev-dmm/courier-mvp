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
 * - PELTT01.wsdl or PELTT03.WSDL - Shipping Status (Track & Trace) - REQUIRED
 *   Download from Elta FTP and place in wsdl/ folder
 *   (PELTT01.wsdl is tried first)
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
     * WSDL: PELTT01.wsdl or PELTT03.WSDL
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
        
        // Try PELTT01.wsdl first, fallback to PELTT03.WSDL
        $local_wsdl_01 = COURIER_INTELLIGENCE_PLUGIN_DIR . 'wsdl/PELTT01.wsdl';
        if (file_exists($local_wsdl_01)) {
            $wsdl_path = $this->get_wsdl_path('PELTT01.wsdl');
        } else {
            $wsdl_path = $this->get_wsdl_path('PELTT03.WSDL');
        }
        
        // Prepare SOAP request
        $soap_data = array(
            'wpel_code' => $this->apost_code,
            'wpel_user' => $this->user_code,
            'wpel_pass' => $this->user_pass,
            'wpel_vg' => $search_type === 'voucher' ? $tracking_number : '',
            'wpel_ref' => $search_type === 'reference' ? $tracking_number : '',
            'wpel_flag' => $search_type === 'voucher' ? '1' : '2',
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
     * - Request/response field names (e.g., wpel_code, wpel_user, wpel_vg)
     * - Data types and structure
     * 
     * PHP's SoapClient cannot work without a valid WSDL. Elta provides WSDL files
     * via FTP - download them and place in the wsdl/ folder.
     * 
     * Uses file:// protocol for local files.
     * 
     * @param string $wsdl_filename WSDL filename (e.g., 'PELTT01.wsdl' or 'PELTT03.WSDL')
     * @return string WSDL path with file:// protocol for local files, or URL for remote
     */
    private function get_wsdl_path($wsdl_filename) {
        // Try local WSDL file first (recommended approach)
        $local_wsdl = COURIER_INTELLIGENCE_PLUGIN_DIR . 'wsdl/' . $wsdl_filename;
        if (file_exists($local_wsdl)) {
            // Use file:// protocol
            return "file://" . $local_wsdl;
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
     * Uses SSL context with disabled verification
     * 
     * @param string $wsdl_path WSDL file path (local file with file:// or URL)
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
            // Create SSL context with disabled verification
            // This is needed because Elta's SOAP endpoints may have SSL certificate issues
            $context = stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ),
            ));
            
            // Create SOAP client options
            $soap_options = array(
                'stream_context' => $context,
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE, // Don't cache WSDL for development
            );
            
            // If using URL (not file://), add location option
            if (filter_var($wsdl_path, FILTER_VALIDATE_URL) && strpos($wsdl_path, 'file://') !== 0) {
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
     * Handles both object and array responses
     * 
     * @param array|object $response API response
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
        
        // Check st_flag (can be st_flag or ST-FLAG depending on WSDL version)
        $st_flag = -1;
        if (isset($response['st_flag'])) {
            $st_flag = intval($response['st_flag']);
        } elseif (isset($response['ST-FLAG'])) {
            $st_flag = intval($response['ST-FLAG']);
        }
        
        if ($st_flag !== 0) {
            $error_title = 'Unknown error';
            if (isset($response['st_title'])) {
                $error_title = $response['st_title'];
            } elseif (isset($response['ST-TITLE'])) {
                $error_title = $response['ST-TITLE'];
            }
            return new WP_Error('tracking_error', 'Tracking failed: ' . $error_title, array(
                'st_flag' => $st_flag,
                'st_title' => $error_title,
            ));
        }
        
        // Parse tracking events
        // Implementation accesses web_status as an object array
        $events = array();
        
        // Try to get web_status array
        if (isset($response['web_status']) && is_array($response['web_status'])) {
            foreach ($response['web_status'] as $checkpoint) {
                if (is_object($checkpoint)) {
                    $checkpoint = json_decode(json_encode($checkpoint), true);
                }
                if (!empty($checkpoint['web_status_title'])) {
                    $events[] = array(
                        'date' => $checkpoint['web_date'] ?? '',
                        'time' => $checkpoint['web_time'] ?? '',
                        'station' => $checkpoint['web_station'] ?? '',
                        'status_title' => $checkpoint['web_status_title'] ?? '',
                        'remarks' => $checkpoint['web_remarks'] ?? '',
                    );
                }
            }
        }
        
        // Fallback: try indexed arrays (WEB_STATUS_COUNTER style)
        if (empty($events)) {
            $status_counter = 0;
            if (isset($response['WEB_STATUS_COUNTER'])) {
                $status_counter = intval($response['WEB_STATUS_COUNTER']);
            } elseif (isset($response['web_status_counter'])) {
                $status_counter = intval($response['web_status_counter']);
            }
            
            for ($i = 0; $i < $status_counter; $i++) {
                $event = array(
                    'date' => isset($response['WEB_DATE'][$i]) ? $response['WEB_DATE'][$i] : (isset($response['web_date'][$i]) ? $response['web_date'][$i] : ''),
                    'time' => isset($response['WEB_TIME'][$i]) ? $response['WEB_TIME'][$i] : (isset($response['web_time'][$i]) ? $response['web_time'][$i] : ''),
                    'station' => isset($response['WEB_STATION'][$i]) ? $response['WEB_STATION'][$i] : (isset($response['web_station'][$i]) ? $response['web_station'][$i] : ''),
                    'status_title' => isset($response['WEB_STATUS_TITLE'][$i]) ? $response['WEB_STATUS_TITLE'][$i] : (isset($response['web_status_title'][$i]) ? $response['web_status_title'][$i] : ''),
                    'remarks' => isset($response['WEB_REMARKS'][$i]) ? $response['WEB_REMARKS'][$i] : (isset($response['web_remarks'][$i]) ? $response['web_remarks'][$i] : ''),
                );
                if (!empty($event['status_title'])) {
                    $events[] = $event;
                }
            }
        }
        
        // Check if delivered (POD = Proof of Delivery)
        $is_delivered = false;
        $pod_date = '';
        $pod_time = '';
        $pod_name = '';
        
        if (isset($response['POD_DATE']) && !empty($response['POD_DATE'])) {
            $is_delivered = true;
            $pod_date = $response['POD_DATE'];
            $pod_time = $response['POD_TIME'] ?? '';
            $pod_name = $response['POD_NAME'] ?? '';
        } elseif (isset($response['pod_date']) && !empty($response['pod_date'])) {
            $is_delivered = true;
            $pod_date = $response['pod_date'];
            $pod_time = $response['pod_time'] ?? '';
            $pod_name = $response['pod_name'] ?? '';
        }
        
        // Get current status (latest event)
        // Note: Elta returns events in descending order (most recent first)
        $current_status_title = '';
        if (!empty($events)) {
            $latest_event = reset($events); // First element is the most recent
            $current_status_title = $latest_event['status_title'] ?? '';
        }
        
        // Map Elta status to normalized status
        // This is important: Elta returns actual status titles, not normalized statuses
        $current_status = $this->map_elta_status($current_status_title, $pod_date);
        
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
            'voucher_code' => $response['VG_CODE'] ?? $response['vg_code'] ?? null,
            'reference_number' => $response['REF_NO'] ?? $response['ref_no'] ?? null,
        );
        
        // Delivery information (POD = Proof of Delivery)
        if ($is_delivered) {
            $tracking_data['delivery_date'] = $pod_date;
            $tracking_data['delivery_time'] = $pod_time;
            $tracking_data['recipient_name'] = $pod_name;
        }
        
        // Include raw response for debugging/advanced use
        $tracking_data['raw_response'] = $response;
        
        return $tracking_data;
    }
    
    /**
     * Map Elta Courier status title to normalized status
     * 
     * Elta API returns actual status titles in Greek (e.g., "ΔΗΜΙΟΥΡΓΙΑ ΣΥ.ΔΕ.ΤΑ. ΑΠΟ ΠΕΛΑΤΗ")
     * This function maps them to normalized statuses: created, in_transit, delivered, returned, issue, unknown
     * 
     * Normalized statuses: created, in_transit, delivered, returned, issue, unknown
     * 
     * @param string $web_status_title Elta status title (e.g., "ΔΗΜΙΟΥΡΓΙΑ ΣΥ.ΔΕ.ΤΑ. ΑΠΟ ΠΕΛΑΤΗ")
     * @param string $pod_date POD (Proof of Delivery) date if delivered
     * @return string Normalized status: created, in_transit, delivered, returned, issue, unknown
     */
    private function map_elta_status($web_status_title, $pod_date = '') {
        // Priority 1: Delivered - check POD date first
        if (!empty($pod_date)) {
            return 'delivered';
        }
        
        // Normalize status title for comparison
        $title = trim(mb_strtoupper($web_status_title, 'UTF-8'));
        
        if (empty($title)) {
            return 'unknown';
        }
        
        // Priority 1: Delivered - check for delivery keywords
        if (strpos($title, 'ΠΑΡΑΔΟΣΗ') !== false ||  // Delivery
            strpos($title, 'ΠΑΡΑΔΟΘΗΚΕ') !== false ||  // Delivered
            strpos($title, 'ΠΑΡΑΔΟΘΗΚΕ ΣΤΟΝ ΠΑΡΑΛΗΠΤΗ') !== false ||
            strpos($title, 'DELIVERED') !== false) {
            return 'delivered';
        }
        
        // Priority 2: Returned - shipment returned to sender
        if (strpos($title, 'ΕΠΙΣΤΡΟΦΗ') !== false ||  // Return
            strpos($title, 'ΕΠΙΣΤΡΕΦΕΙ') !== false ||  // Returning
            strpos($title, 'ΕΠΙΣΤΡΟΦΗ ΣΤΟΝ ΑΠΟΣΤΟΛΕΑ') !== false ||
            strpos($title, 'RETURN') !== false ||
            strpos($title, 'RETURNED') !== false) {
            return 'returned';
        }
        
        // Priority 3: Issue/Exception - problems with delivery
        if (strpos($title, 'ΑΔΥΝΑΜΙΑ ΠΑΡΑΔΟΣΗΣ') !== false ||  // Delivery failure
            strpos($title, 'ΑΠΟΡΡΙΦΘΗΚΕ') !== false ||  // Rejected
            strpos($title, 'ΠΡΟΒΛΗΜΑ') !== false ||  // Problem
            strpos($title, 'ΑΚΥΡΩΘΗΚΕ') !== false ||  // Cancelled
            strpos($title, 'REFUSAL') !== false ||
            strpos($title, 'REJECTED') !== false ||
            strpos($title, 'CANCELLED') !== false ||
            strpos($title, 'CANCELED') !== false ||
            strpos($title, 'DAMAGED') !== false ||
            strpos($title, 'LOST') !== false) {
            return 'issue';
        }
        
        // Priority 4: Created - voucher just created
        if ($title === 'ΔΗΜΙΟΥΡΓΙΑ ΣΥ.ΔΕ.ΤΑ. ΑΠΟ ΠΕΛΑΤΗ' || 
            $title === 'ΔΗΜΙΟΥΡΓΙΑ ΣΥ.ΔΕ.ΤΑ ΑΠΟ ΠΕΛΑΤΗ' ||
            (strpos($title, 'ΔΗΜΙΟΥΡΓΙΑ') !== false && strpos($title, 'ΠΕΛΑΤΗ') !== false) ||
            strpos($title, 'CREATED') !== false ||
            strpos($title, 'PRINTED') !== false ||
            strpos($title, 'LABEL') !== false) {
            return 'created';
        }
        
        // Priority 5: In transit - shipment is moving
        if (strpos($title, 'ΑΝΑΧΩΡΗΣΗ') !== false ||  // Departure
            strpos($title, 'ΑΦΙΞΗ') !== false ||      // Arrival
            strpos($title, 'ΣΤΟ ΚΕΝΤΡΙΚΟ ΚΕΝΤΡΟ') !== false ||  // At sorting center
            strpos($title, 'ΑΠΟ ΚΕΝΤΡΙΚΟ ΚΕΝΤΡΟ') !== false ||  // From sorting center
            strpos($title, 'ΣΕ ΜΕΤΑΦΟΡΑ') !== false ||  // In transport
            strpos($title, 'ΜΕΤΑΦΟΡΑ') !== false ||
            strpos($title, 'DEPARTURE') !== false ||
            strpos($title, 'ARRIVAL') !== false ||
            strpos($title, 'IN TRANSIT') !== false ||
            strpos($title, 'TRANSPORT') !== false ||
            strpos($title, 'OUT FOR DELIVERY') !== false) {
            return 'in_transit';
        }
        
        // Fallback: if we have a status title but don't recognize it, assume in_transit
        // (better than "unknown" if there's actual tracking data)
        return 'in_transit';
    }
}
