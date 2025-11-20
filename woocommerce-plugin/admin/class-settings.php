<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Settings Page
 */
class Courier_Intelligence_Settings {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_courier_intelligence_scan_meta_keys', array($this, 'ajax_scan_meta_keys'));
        add_action('wp_ajax_courier_intelligence_test_elta_voucher', array($this, 'ajax_test_elta_voucher'));
        add_action('wp_ajax_courier_intelligence_test_acs_voucher', array($this, 'ajax_test_acs_voucher'));
        add_action('admin_post_courier_intelligence_clear_logs', array($this, 'handle_clear_logs'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Courier Intelligence',
            'Courier Intelligence',
            'manage_woocommerce',
            'courier-intelligence',
            array($this, 'render_settings_page'),
            'dashicons-truck',
            30
        );
        
        // Add Logs submenu
        add_submenu_page(
            'courier-intelligence',
            'Activity Logs',
            'Activity Logs',
            'manage_woocommerce',
            'courier-intelligence-logs',
            array($this, 'render_logs_page')
        );
    }
    
    /**
     * Handle clear logs action
     */
    public function handle_clear_logs() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        check_admin_referer('courier_intelligence_clear_logs');
        
        Courier_Intelligence_Logger::clear_logs();
        
        wp_redirect(add_query_arg(array(
            'page' => 'courier-intelligence-logs',
            'cleared' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('courier_intelligence_settings', 'courier_intelligence_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        // Φόρτωσε jQuery σε όλες τις admin σελίδες του plugin
        if (strpos($hook, 'courier-intelligence') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
    }
    
    /**
     * Get available couriers
     */
    private function get_couriers() {
        return array(
            'acs' => 'ACS',
            'elta' => 'Elta',
            'speedex' => 'Speedex',
            'boxnow' => 'Boxnow',
            'geniki_taxidromiki' => 'Geniki Taxidromiki',
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Global API settings
        if (isset($input['api_endpoint'])) {
            $sanitized['api_endpoint'] = esc_url_raw($input['api_endpoint']);
        }
        
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['api_secret'])) {
            $sanitized['api_secret'] = sanitize_text_field($input['api_secret']);
        }
        
        if (isset($input['hash_salt'])) {
            $sanitized['hash_salt'] = sanitize_text_field($input['hash_salt']);
        }
        
        // Legacy support - keep old courier_name and voucher_meta_key for backward compatibility
        if (isset($input['courier_name'])) {
            $sanitized['courier_name'] = sanitize_text_field($input['courier_name']);
        }
        
        if (isset($input['voucher_meta_key'])) {
            $sanitized['voucher_meta_key'] = sanitize_text_field($input['voucher_meta_key']);
        }
        
        // Courier-specific settings
        $couriers = $this->get_couriers();
        foreach ($couriers as $courier_key => $courier_name) {
            if (isset($input['couriers'][$courier_key])) {
                $courier_data = $input['couriers'][$courier_key];
                
                $sanitized['couriers'][$courier_key] = array();
                
                // Elta-specific fields
                if ($courier_key === 'elta') {
                    if (isset($courier_data['api_endpoint'])) {
                        $sanitized['couriers'][$courier_key]['api_endpoint'] = esc_url_raw($courier_data['api_endpoint']);
                    }
                    if (isset($courier_data['user_code'])) {
                        $sanitized['couriers'][$courier_key]['user_code'] = sanitize_text_field($courier_data['user_code']);
                    }
                    // Legacy support for username
                    if (isset($courier_data['username']) && empty($courier_data['user_code'])) {
                        $sanitized['couriers'][$courier_key]['user_code'] = sanitize_text_field($courier_data['username']);
                    }
                    if (isset($courier_data['user_pass'])) {
                        $sanitized['couriers'][$courier_key]['user_pass'] = sanitize_text_field($courier_data['user_pass']);
                    }
                    // Legacy support for password
                    if (isset($courier_data['password']) && empty($courier_data['user_pass'])) {
                        $sanitized['couriers'][$courier_key]['user_pass'] = sanitize_text_field($courier_data['password']);
                    }
                    if (isset($courier_data['apost_code'])) {
                        $sanitized['couriers'][$courier_key]['apost_code'] = sanitize_text_field($courier_data['apost_code']);
                    }
                    // Legacy support for sender_code
                    if (isset($courier_data['sender_code']) && empty($courier_data['apost_code'])) {
                        $sanitized['couriers'][$courier_key]['apost_code'] = sanitize_text_field($courier_data['sender_code']);
                    }
                    if (isset($courier_data['apost_sub_code'])) {
                        $sanitized['couriers'][$courier_key]['apost_sub_code'] = sanitize_text_field($courier_data['apost_sub_code']);
                    }
                    if (isset($courier_data['user_lang'])) {
                        $sanitized['couriers'][$courier_key]['user_lang'] = sanitize_text_field($courier_data['user_lang']);
                    }
                    if (isset($courier_data['test_mode'])) {
                        $sanitized['couriers'][$courier_key]['test_mode'] = $courier_data['test_mode'] === 'yes' ? 'yes' : '';
                    }
                }
                
                // ACS-specific fields
                if ($courier_key === 'acs') {
                    if (isset($courier_data['api_endpoint'])) {
                        $sanitized['couriers'][$courier_key]['api_endpoint'] = esc_url_raw($courier_data['api_endpoint']);
                    }
                    if (isset($courier_data['api_key'])) {
                        $sanitized['couriers'][$courier_key]['api_key'] = sanitize_text_field($courier_data['api_key']);
                    }
                    // Legacy support for acs_api_key
                    if (isset($courier_data['acs_api_key']) && empty($courier_data['api_key'])) {
                        $sanitized['couriers'][$courier_key]['api_key'] = sanitize_text_field($courier_data['acs_api_key']);
                    }
                    if (isset($courier_data['company_id'])) {
                        $sanitized['couriers'][$courier_key]['company_id'] = sanitize_text_field($courier_data['company_id']);
                    }
                    if (isset($courier_data['company_password'])) {
                        $sanitized['couriers'][$courier_key]['company_password'] = sanitize_text_field($courier_data['company_password']);
                    }
                    if (isset($courier_data['user_id'])) {
                        $sanitized['couriers'][$courier_key]['user_id'] = sanitize_text_field($courier_data['user_id']);
                    }
                    if (isset($courier_data['user_password'])) {
                        $sanitized['couriers'][$courier_key]['user_password'] = sanitize_text_field($courier_data['user_password']);
                    }
                    if (isset($courier_data['test_mode'])) {
                        $sanitized['couriers'][$courier_key]['test_mode'] = $courier_data['test_mode'] === 'yes' ? 'yes' : '';
                    }
                    if (isset($courier_data['test_endpoint'])) {
                        $sanitized['couriers'][$courier_key]['test_endpoint'] = esc_url_raw($courier_data['test_endpoint']);
                    }
                }
                
                // Common fields for all couriers
                if (isset($courier_data['api_key'])) {
                    $sanitized['couriers'][$courier_key]['api_key'] = sanitize_text_field($courier_data['api_key']);
                }
                
                if (isset($courier_data['api_secret'])) {
                    $sanitized['couriers'][$courier_key]['api_secret'] = sanitize_text_field($courier_data['api_secret']);
                }
                
                if (isset($courier_data['voucher_meta_key'])) {
                    $sanitized['couriers'][$courier_key]['voucher_meta_key'] = sanitize_text_field($courier_data['voucher_meta_key']);
                }
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Scan orders for meta keys (AJAX handler)
     */
    /**
     * AJAX handler for testing Elta voucher tracking
     */
    public function ajax_test_elta_voucher() {
        check_ajax_referer('courier_intelligence_scan', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $voucher_number = isset($_POST['voucher_number']) ? trim(sanitize_text_field($_POST['voucher_number'])) : '';
        
        if (empty($voucher_number)) {
            wp_send_json_error(array('message' => 'Voucher number is required'));
            return;
        }
        
        // Load Elta API client
        require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-elta-api-client.php';
        
        $settings = get_option('courier_intelligence_settings', array());
        $elta_settings = $settings['couriers']['elta'] ?? array();
        
        $client = new Courier_Intelligence_Elta_API_Client($elta_settings);
        
        // Test the voucher
        $result = $client->get_voucher_status($voucher_number);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'error_code' => $result->get_error_code(),
                'error_data' => $result->get_error_data(),
            ));
            return;
        }
        
        // Format the response for display
        $formatted_result = array(
            'success' => true,
            'voucher_code' => $result['voucher_code'] ?? $voucher_number,
            'status' => $result['status'] ?? 'unknown',
            'status_title' => $result['status_title'] ?? '',
            'delivered' => $result['delivered'] ?? false,
            'delivery_date' => $result['delivery_date'] ?? '',
            'delivery_time' => $result['delivery_time'] ?? '',
            'recipient_name' => $result['recipient_name'] ?? '',
            'events_count' => count($result['events'] ?? array()),
            'events' => $result['events'] ?? array(),
            'raw_response' => $result['raw_response'] ?? array(),
        );
        
        wp_send_json_success($formatted_result);
    }
    
    /**
     * AJAX handler for testing ACS voucher tracking
     */
    public function ajax_test_acs_voucher() {
        check_ajax_referer('courier_intelligence_scan', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $voucher_number = isset($_POST['voucher_number']) ? trim(sanitize_text_field($_POST['voucher_number'])) : '';
        
        if (empty($voucher_number)) {
            wp_send_json_error(array('message' => 'Voucher number is required'));
            return;
        }
        
        // Load ACS API client
        require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-acs-api-client.php';
        
        $settings = get_option('courier_intelligence_settings', array());
        $acs_settings = $settings['couriers']['acs'] ?? array();
        
        $client = new Courier_Intelligence_ACS_API_Client($acs_settings);
        
        // Test the voucher
        $result = $client->get_voucher_status($voucher_number);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'error_code' => $result->get_error_code(),
                'error_data' => $result->get_error_data(),
            ));
            return;
        }
        
        // Format the response for display
        $formatted_result = array(
            'success' => true,
            'voucher_code' => $result['voucher_code'] ?? $voucher_number,
            'status' => $result['status'] ?? 'unknown',
            'status_title' => $result['status_title'] ?? '',
            'delivered' => $result['delivered'] ?? false,
            'returned' => $result['returned'] ?? false,
            'delivery_date' => $result['delivery_date'] ?? '',
            'delivery_time' => $result['delivery_time'] ?? '',
            'recipient_name' => $result['recipient_name'] ?? '',
            'events_count' => count($result['events'] ?? array()),
            'events' => $result['events'] ?? array(),
            'raw_response' => $result['raw_response'] ?? array(),
        );
        
        wp_send_json_success($formatted_result);
    }
    
    public function ajax_scan_meta_keys() {
        check_ajax_referer('courier_intelligence_scan', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        if (!function_exists('wc_get_orders')) {
            wp_send_json_error(array('message' => 'WooCommerce is not active'));
            return;
        }
        
        $limit = isset($_POST['limit']) ? max(1, min(2000, absint($_POST['limit']))) : 200;
        $include_private = isset($_POST['include_private']) && $_POST['include_private'] === 'yes';
        
        $ids = wc_get_orders(array(
            'type'    => 'shop_order',
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'ids',
            'status'  => array_keys(wc_get_order_statuses()),
        ));
        
        $order_meta_keys = array();
        $order_meta_samples = array();
        
        foreach ($ids as $oid) {
            $o = wc_get_order($oid);
            if (!$o) continue;
            
            foreach ($o->get_meta_data() as $m) {
                $k = $m->key;
                if (!$include_private && isset($k[0]) && $k[0] === '_') continue;
                
                $order_meta_keys[$k] = ($order_meta_keys[$k] ?? 0) + 1;
                
                if (!isset($order_meta_samples[$k])) {
                    $val = maybe_unserialize($m->value);
                    if (is_string($val) && strlen($val) > 0) {
                        $order_meta_samples[$k] = strlen($val) > 100 ? substr($val, 0, 100) . '...' : $val;
                    } else {
                        $order_meta_samples[$k] = is_scalar($val) ? $val : '[complex data]';
                    }
                }
            }
        }
        
        ksort($order_meta_keys, SORT_NATURAL | SORT_FLAG_CASE);
        
        wp_send_json_success(array(
            'scanned_orders' => count($ids),
            'meta_keys' => $order_meta_keys,
            'samples' => $order_meta_samples,
        ));
    }
    
    /**
     * Get order meta keys (for initial load)
     */
    private function get_order_meta_keys($limit = 200, $include_private = true) {
        if (!function_exists('wc_get_orders')) {
            return array();
        }
        
        $ids = wc_get_orders(array(
            'type'    => 'shop_order',
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'ids',
            'status'  => array_keys(wc_get_order_statuses()),
        ));
        
        $order_meta_keys = array();
        
        foreach ($ids as $oid) {
            $o = wc_get_order($oid);
            if (!$o) continue;
            
            foreach ($o->get_meta_data() as $m) {
                $k = $m->key;
                if (!$include_private && isset($k[0]) && $k[0] === '_') continue;
                
                $order_meta_keys[$k] = ($order_meta_keys[$k] ?? 0) + 1;
            }
        }
        
        ksort($order_meta_keys, SORT_NATURAL | SORT_FLAG_CASE);
        
        return $order_meta_keys;
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $settings = get_option('courier_intelligence_settings', array());
        $meta_keys = $this->get_order_meta_keys(200, true);
        $couriers = $this->get_couriers();
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        
        ?>
        <div class="wrap">
            <h1>Courier Intelligence Settings</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=courier-intelligence&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    General
                </a>
                <?php foreach ($couriers as $courier_key => $courier_name): ?>
                    <a href="?page=courier-intelligence&tab=<?php echo esc_attr($courier_key); ?>" 
                       class="nav-tab <?php echo $active_tab === $courier_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($courier_name); ?>
                    </a>
                <?php endforeach; ?>
            </h2>
            
            <form method="post" action="options.php">
                <?php settings_fields('courier_intelligence_settings'); ?>
                
                <?php if ($active_tab === 'general'): ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="api_endpoint">API Endpoint</label>
                            </th>
                            <td>
                                <input type="url" 
                                       id="api_endpoint" 
                                       name="courier_intelligence_settings[api_endpoint]" 
                                       value="<?php echo esc_attr($settings['api_endpoint'] ?? ''); ?>" 
                                       class="regular-text" 
                                       placeholder="https://api.example.com" />
                                <p class="description">The base URL of your Courier Intelligence API</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="api_key">API Key</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="api_key" 
                                       name="courier_intelligence_settings[api_key]" 
                                       value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" 
                                       class="regular-text" />
                                <p class="description">Your API key from the Courier Intelligence platform (used as default if not set per courier)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="api_secret">API Secret</label>
                            </th>
                            <td>
                                <input type="password" 
                                       id="api_secret" 
                                       name="courier_intelligence_settings[api_secret]" 
                                       value="<?php echo esc_attr($settings['api_secret'] ?? ''); ?>" 
                                       class="regular-text" />
                                <p class="description">Your API secret for HMAC signing (used as default if not set per courier)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="hash_salt">Customer Hash Salt</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="hash_salt" 
                                       name="courier_intelligence_settings[hash_salt]" 
                                       value="<?php echo esc_attr($settings['hash_salt'] ?? ''); ?>" 
                                       class="regular-text" 
                                       placeholder="Enter the same salt as in Laravel .env (CUSTOMER_HASH_SALT)" />
                                <p class="description">
                                    <strong>GDPR Compliance:</strong> This salt is used to hash customer PII before transmission.
                                    <br>Must match <code>CUSTOMER_HASH_SALT</code> in your Laravel .env file.
                                    <br>Generate a secure random string (minimum 32 characters).
                                    <br><strong>Important:</strong> This must be the same across all installations for cross-shop customer matching.
                                </p>
                            </td>
                        </tr>
                    </table>
                <?php else: ?>
                    <?php 
                    $courier_key = $active_tab;
                    $courier_name = $couriers[$courier_key] ?? $courier_key;
                    $courier_settings = $settings['couriers'][$courier_key] ?? array();
                    ?>
                    <h2><?php echo esc_html($courier_name); ?> Settings</h2>
                    <table class="form-table">
                        <?php if ($courier_key === 'elta'): ?>
                            <!-- Elta-specific fields -->
                            <tr>
                                <th scope="row">
                                    <label for="courier_elta_api_endpoint">API Endpoint</label>
                                </th>
                                <td>
                                    <input type="url" 
                                           id="courier_elta_api_endpoint" 
                                           name="courier_intelligence_settings[couriers][elta][api_endpoint]" 
                                           value="<?php echo esc_attr($courier_settings['api_endpoint'] ?? 'https://wsstage.elta-courier.gr'); ?>" 
                                           class="regular-text" 
                                           placeholder="https://wsstage.elta-courier.gr" />
                                    <p class="description">
                                        Elta Courier Web Services endpoint URL.
                                        <br>Test: <code>https://wsstage.elta-courier.gr</code>
                                        <br>Production: <code>https://customers.elta-courier.gr</code>
                                        <br><strong>Note:</strong> WSDL files should be downloaded from FTP and placed in <code>wsdl/</code> folder for best results.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_elta_user_code">User Code</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="courier_elta_user_code" 
                                           name="courier_intelligence_settings[couriers][elta][user_code]" 
                                           value="<?php echo esc_attr($courier_settings['user_code'] ?? $courier_settings['username'] ?? ''); ?>" 
                                           class="regular-text" 
                                           maxlength="7" />
                                    <p class="description">Elta Courier User Code (7-digit code, PEL-USER-CODE)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_elta_user_pass">Password</label>
                                </th>
                                <td>
                                    <input type="password" 
                                           id="courier_elta_user_pass" 
                                           name="courier_intelligence_settings[couriers][elta][user_pass]" 
                                           value="<?php echo esc_attr($courier_settings['user_pass'] ?? $courier_settings['password'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">Elta Courier User Password (PEL-USER-PASS)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_elta_apost_code">Sender Code</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="courier_elta_apost_code" 
                                           name="courier_intelligence_settings[couriers][elta][apost_code]" 
                                           value="<?php echo esc_attr($courier_settings['apost_code'] ?? $courier_settings['sender_code'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">Elta Courier Sender Code - Master (PEL-APOST-CODE, Only Master)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_elta_apost_sub_code">Sub Code (Optional)</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="courier_elta_apost_sub_code" 
                                           name="courier_intelligence_settings[couriers][elta][apost_sub_code]" 
                                           value="<?php echo esc_attr($courier_settings['apost_sub_code'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">Elta Courier Sub Code (PEL-APOST-SUB-CODE, optional)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_elta_user_lang">Display Language</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="courier_elta_user_lang" 
                                           name="courier_intelligence_settings[couriers][elta][user_lang]" 
                                           value="<?php echo esc_attr($courier_settings['user_lang'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">Display Language (PEL-USER-LANG, blank for now)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_elta_test_mode">Test Mode</label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               id="courier_elta_test_mode" 
                                               name="courier_intelligence_settings[couriers][elta][test_mode]" 
                                               value="yes" 
                                               <?php checked($courier_settings['test_mode'] ?? '', 'yes'); ?> />
                                        Enable test mode (use test endpoint)
                                    </label>
                                    <p class="description">Enable this to use Elta's test/sandbox environment</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_elta_test_voucher">Test Voucher Tracking</label>
                                </th>
                                <td>
                                    <div id="elta-voucher-test-container">
                                        <input type="text" 
                                               id="courier_elta_test_voucher" 
                                               placeholder="Enter voucher number (13 digits)"
                                               class="regular-text" 
                                               style="max-width: 300px;" />
                                        <button type="button" 
                                                id="courier_elta_test_voucher_btn" 
                                                class="button button-secondary"
                                                style="margin-left: 10px;">
                                            Test Voucher
                                        </button>
                                        <div id="elta-voucher-test-result" style="margin-top: 15px; display: none;">
                                            <div class="notice" style="padding: 10px; margin: 10px 0;">
                                                <p id="elta-voucher-test-message"></p>
                                                <pre id="elta-voucher-test-details" style="background: #f5f5f5; padding: 10px; overflow-x: auto; max-height: 400px; display: none;"></pre>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="description">
                                        Enter a voucher number to test the Elta API connection and retrieve tracking information.
                                    </p>
                                </td>
                            </tr>
                        <?php elseif ($courier_key === 'acs'): ?>
                            <!-- ACS-specific fields -->
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_api_endpoint">API Endpoint</label>
                                </th>
                                <td>
                                    <input type="url" 
                                           id="courier_acs_api_endpoint" 
                                           name="courier_intelligence_settings[couriers][acs][api_endpoint]" 
                                           value="<?php echo esc_attr($courier_settings['api_endpoint'] ?? 'https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest'); ?>" 
                                           class="regular-text" 
                                           placeholder="https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest" />
                                    <p class="description">
                                        ACS Courier REST API endpoint URL.
                                        <br>Production: <code>https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_api_key">API Key</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="courier_acs_api_key" 
                                           name="courier_intelligence_settings[couriers][acs][api_key]" 
                                           value="<?php echo esc_attr($courier_settings['api_key'] ?? $courier_settings['acs_api_key'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">ACS API Key (ACSApiKey header). Provided by ACS.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_company_id">Company ID</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="courier_acs_company_id" 
                                           name="courier_intelligence_settings[couriers][acs][company_id]" 
                                           value="<?php echo esc_attr($courier_settings['company_id'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">ACS Company ID (unique code given by ACS)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_company_password">Company Password</label>
                                </th>
                                <td>
                                    <input type="password" 
                                           id="courier_acs_company_password" 
                                           name="courier_intelligence_settings[couriers][acs][company_password]" 
                                           value="<?php echo esc_attr($courier_settings['company_password'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">ACS Company Password (unique code given by ACS)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_user_id">User ID</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="courier_acs_user_id" 
                                           name="courier_intelligence_settings[couriers][acs][user_id]" 
                                           value="<?php echo esc_attr($courier_settings['user_id'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">ACS User ID (unique code given by ACS)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_user_password">User Password</label>
                                </th>
                                <td>
                                    <input type="password" 
                                           id="courier_acs_user_password" 
                                           name="courier_intelligence_settings[couriers][acs][user_password]" 
                                           value="<?php echo esc_attr($courier_settings['user_password'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">ACS User Password (unique code given by ACS)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_test_mode">Test Mode</label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               id="courier_acs_test_mode" 
                                               name="courier_intelligence_settings[couriers][acs][test_mode]" 
                                               value="yes" 
                                               <?php checked($courier_settings['test_mode'] ?? '', 'yes'); ?> />
                                        Enable test mode (use test endpoint)
                                    </label>
                                    <p class="description">Enable this to use ACS's test/sandbox environment (if available)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_test_endpoint">Test Endpoint (Optional)</label>
                                </th>
                                <td>
                                    <input type="url" 
                                           id="courier_acs_test_endpoint" 
                                           name="courier_intelligence_settings[couriers][acs][test_endpoint]" 
                                           value="<?php echo esc_attr($courier_settings['test_endpoint'] ?? ''); ?>" 
                                           class="regular-text" 
                                           placeholder="https://test-webservices.acscourier.net/ACSRestServices/api/ACSAutoRest" />
                                    <p class="description">Test endpoint URL (only used if Test Mode is enabled)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_acs_test_voucher">Test Voucher Tracking</label>
                                </th>
                                <td>
                                    <div id="acs-voucher-test-container">
                                        <input type="text" 
                                               id="courier_acs_test_voucher" 
                                               placeholder="Enter voucher number (10 digits)"
                                               class="regular-text" 
                                               style="max-width: 300px;" />
                                        <button type="button" 
                                                id="courier_acs_test_voucher_btn" 
                                                class="button button-secondary"
                                                style="margin-left: 10px;">
                                            Test Voucher
                                        </button>
                                        <div id="acs-voucher-test-result" style="margin-top: 15px; display: none;">
                                            <div class="notice" style="padding: 10px; margin: 10px 0;">
                                                <p id="acs-voucher-test-message"></p>
                                                <pre id="acs-voucher-test-details" style="background: #f5f5f5; padding: 10px; overflow-x: auto; max-height: 400px; display: none;"></pre>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="description">
                                        Enter a voucher number to test the ACS API connection and retrieve tracking information.
                                    </p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <!-- Generic courier fields -->
                            <tr>
                                <th scope="row">
                                    <label for="courier_<?php echo esc_attr($courier_key); ?>_api_key">API Key</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="courier_<?php echo esc_attr($courier_key); ?>_api_key" 
                                           name="courier_intelligence_settings[couriers][<?php echo esc_attr($courier_key); ?>][api_key]" 
                                           value="<?php echo esc_attr($courier_settings['api_key'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">API key for <?php echo esc_html($courier_name); ?>. Leave empty to use the global API key.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="courier_<?php echo esc_attr($courier_key); ?>_api_secret">API Secret</label>
                                </th>
                                <td>
                                    <input type="password" 
                                           id="courier_<?php echo esc_attr($courier_key); ?>_api_secret" 
                                           name="courier_intelligence_settings[couriers][<?php echo esc_attr($courier_key); ?>][api_secret]" 
                                           value="<?php echo esc_attr($courier_settings['api_secret'] ?? ''); ?>" 
                                           class="regular-text" />
                                    <p class="description">API secret for <?php echo esc_html($courier_name); ?>. Leave empty to use the global API secret.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row">
                                <label for="courier_<?php echo esc_attr($courier_key); ?>_voucher_meta_key">Voucher/Tracking Meta Key</label>
                            </th>
                            <td>
                                <select id="courier_<?php echo esc_attr($courier_key); ?>_voucher_meta_key" 
                                        name="courier_intelligence_settings[couriers][<?php echo esc_attr($courier_key); ?>][voucher_meta_key]" 
                                        class="regular-text courier-meta-key-select"
                                        data-courier="<?php echo esc_attr($courier_key); ?>">
                                    <option value="">-- Select Meta Key --</option>
                                    <?php foreach ($meta_keys as $key => $count): ?>
                                        <option value="<?php echo esc_attr($key); ?>" 
                                                <?php selected($courier_settings['voucher_meta_key'] ?? '', $key); ?>>
                                            <?php echo esc_html($key); ?> (found in <?php echo $count; ?> order<?php echo $count !== 1 ? 's' : ''; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    Select which order meta key contains the voucher/tracking number for <?php echo esc_html($courier_name); ?>.
                                    <button type="button" class="button button-secondary scan-meta-keys-btn" 
                                            data-courier="<?php echo esc_attr($courier_key); ?>" 
                                            style="margin-left: 10px;">
                                        Scan Orders for Meta Keys
                                    </button>
                                </p>
                                <div id="meta-keys-scan-result-<?php echo esc_attr($courier_key); ?>" style="margin-top: 10px; display: none;"></div>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card" style="margin-top: 20px;">
                <h2 class="title">Sync Orders</h2>
                <p>Manually sync orders to the Courier Intelligence dashboard. This will send order data and any existing vouchers/tracking numbers.</p>
                <p>
                    <button type="button" id="sync-all-orders" class="button button-primary">
                        Sync All Orders
                    </button>
                    <span id="sync-all-orders-status" style="margin-left: 10px;"></span>
                </p>
                <div id="sync-all-orders-progress" style="display: none; margin-top: 10px;">
                    <div class="progress-bar" style="width: 100%; background: #f0f0f0; border-radius: 4px; overflow: hidden;">
                        <div class="progress-bar-fill" style="width: 0%; height: 20px; background: #2271b1; transition: width 0.3s;"></div>
                    </div>
                    <p style="margin-top: 5px; font-size: 12px;">
                        <span id="sync-progress-text">Starting...</span>
                    </p>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Handle scan meta keys for each courier tab
            $('.scan-meta-keys-btn').on('click', function() {
                var $button = $(this);
                var courier = $button.data('courier');
                var $result = $('#meta-keys-scan-result-' + courier);
                var $select = $('#courier_' + courier + '_voucher_meta_key');
                var originalText = $button.text();
                
                $button.prop('disabled', true).text('Scanning...');
                $result.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'courier_intelligence_scan_meta_keys',
                        nonce: '<?php echo wp_create_nonce('courier_intelligence_scan'); ?>',
                        limit: 200,
                        include_private: 'yes'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var currentValue = $select.val();
                            var options = '<option value="">-- Select Meta Key --</option>';
                            
                            $.each(data.meta_keys, function(key, count) {
                                var selected = currentValue === key ? ' selected' : '';
                                options += '<option value="' + key + '"' + selected + '>' + 
                                          key + ' (found in ' + count + ' order' + (count !== 1 ? 's' : '') + ')</option>';
                            });
                            
                            $select.html(options);
                            
                            $result.html('<div class="notice notice-success inline"><p>Scanned ' + data.scanned_orders + ' orders. Found ' + 
                                        Object.keys(data.meta_keys).length + ' unique meta keys.</p></div>').show();
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>Error: ' + (response.data.message || 'Unknown error') + '</p></div>').show();
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error inline"><p>Failed to scan orders. Please try again.</p></div>').show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
            
            // Handle Elta voucher test
            $('#courier_elta_test_voucher_btn').on('click', function() {
                var $button = $(this);
                var $input = $('#courier_elta_test_voucher');
                var $result = $('#elta-voucher-test-result');
                var $message = $('#elta-voucher-test-message');
                var $details = $('#elta-voucher-test-details');
                var voucherNumber = $input.val().trim();
                
                if (!voucherNumber) {
                    alert('Please enter a voucher number');
                    return;
                }
                
                $button.prop('disabled', true).text('Testing...');
                $result.hide();
                $message.html('');
                $details.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'courier_intelligence_test_elta_voucher',
                        nonce: '<?php echo wp_create_nonce('courier_intelligence_scan'); ?>',
                        voucher_number: voucherNumber
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var html = '<strong>✓ Success!</strong><br><br>';
                            html += '<strong>Voucher Code:</strong> ' + (data.voucher_code || voucherNumber) + '<br>';
                            html += '<strong>Status:</strong> ' + (data.status || 'unknown') + '<br>';
                            if (data.status_title) {
                                html += '<strong>Status Title:</strong> ' + data.status_title + '<br>';
                            }
                            html += '<strong>Delivered:</strong> ' + (data.delivered ? 'Yes' : 'No') + '<br>';
                            
                            if (data.delivered) {
                                if (data.delivery_date) {
                                    html += '<strong>Delivery Date:</strong> ' + data.delivery_date;
                                    if (data.delivery_time) {
                                        html += ' ' + data.delivery_time;
                                    }
                                    html += '<br>';
                                }
                                if (data.recipient_name) {
                                    html += '<strong>Recipient:</strong> ' + data.recipient_name + '<br>';
                                }
                            }
                            
                            html += '<strong>Tracking Events:</strong> ' + data.events_count + '<br>';
                            
                            if (data.events && data.events.length > 0) {
                                html += '<br><strong>Event History:</strong><ul style="margin-left: 20px;">';
                                data.events.forEach(function(event) {
                                    html += '<li>';
                                    if (event.date) html += event.date;
                                    if (event.time) html += ' ' + event.time;
                                    if (event.station) html += ' - ' + event.station;
                                    if (event.status_title) html += '<br>&nbsp;&nbsp;<em>' + event.status_title + '</em>';
                                    if (event.remarks) html += '<br>&nbsp;&nbsp;' + event.remarks;
                                    html += '</li>';
                                });
                                html += '</ul>';
                            }
                            
                            html += '<button type="button" class="button button-small" id="show-elta-raw-details" style="margin-top: 10px;">Show Raw Response</button>';
                            
                            $message.html(html);
                            $result.find('.notice').removeClass('notice-error').addClass('notice-success');
                            $result.show();
                            
                            // Store raw response for details view
                            $details.data('raw-response', JSON.stringify(data.raw_response || {}, null, 2));
                            
                            // Toggle raw details
                            $('#show-elta-raw-details').on('click', function() {
                                if ($details.is(':visible')) {
                                    $details.hide();
                                    $(this).text('Show Raw Response');
                                } else {
                                    $details.text($details.data('raw-response')).show();
                                    $(this).text('Hide Raw Response');
                                }
                            });
                        } else {
                            var errorMsg = '<strong>✗ Error:</strong> ' + (response.data.message || 'Unknown error');
                            if (response.data.error_code) {
                                errorMsg += '<br><strong>Error Code:</strong> ' + response.data.error_code;
                            }
                            $message.html(errorMsg);
                            $result.find('.notice').removeClass('notice-success').addClass('notice-error');
                            $result.show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $message.html('<strong>✗ Request Failed:</strong> ' + error);
                        $result.find('.notice').removeClass('notice-success').addClass('notice-error');
                        $result.show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Test Voucher');
                    }
                });
            });
            
            // Allow Enter key to trigger test
            $('#courier_elta_test_voucher').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#courier_elta_test_voucher_btn').click();
                }
            });
            
            // Handle ACS voucher test
            $('#courier_acs_test_voucher_btn').on('click', function() {
                var $button = $(this);
                var $input = $('#courier_acs_test_voucher');
                var $result = $('#acs-voucher-test-result');
                var $message = $('#acs-voucher-test-message');
                var $details = $('#acs-voucher-test-details');
                var voucherNumber = $input.val().trim();
                
                if (!voucherNumber) {
                    alert('Please enter a voucher number');
                    return;
                }
                
                $button.prop('disabled', true).text('Testing...');
                $result.hide();
                $message.html('');
                $details.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'courier_intelligence_test_acs_voucher',
                        nonce: '<?php echo wp_create_nonce('courier_intelligence_scan'); ?>',
                        voucher_number: voucherNumber
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var html = '<strong>✓ Success!</strong><br><br>';
                            html += '<strong>Voucher Code:</strong> ' + (data.voucher_code || voucherNumber) + '<br>';
                            html += '<strong>Status:</strong> ' + (data.status || 'unknown') + '<br>';
                            if (data.status_title) {
                                html += '<strong>Status Title:</strong> ' + data.status_title + '<br>';
                            }
                            html += '<strong>Delivered:</strong> ' + (data.delivered ? 'Yes' : 'No') + '<br>';
                            if (data.returned) {
                                html += '<strong>Returned:</strong> Yes<br>';
                            }
                            
                            if (data.delivered) {
                                if (data.delivery_date) {
                                    html += '<strong>Delivery Date:</strong> ' + data.delivery_date;
                                    if (data.delivery_time) {
                                        html += ' ' + data.delivery_time;
                                    }
                                    html += '<br>';
                                }
                                if (data.recipient_name) {
                                    html += '<strong>Recipient:</strong> ' + data.recipient_name + '<br>';
                                }
                            }
                            
                            html += '<strong>Tracking Events:</strong> ' + data.events_count + '<br>';
                            
                            if (data.events && data.events.length > 0) {
                                html += '<br><strong>Event History:</strong><ul style="margin-left: 20px;">';
                                data.events.forEach(function(event) {
                                    html += '<li>';
                                    if (event.date) html += event.date;
                                    if (event.time) html += ' ' + event.time;
                                    if (event.station) html += ' - ' + event.station;
                                    if (event.status_title) html += '<br>&nbsp;&nbsp;<em>' + event.status_title + '</em>';
                                    if (event.remarks) html += '<br>&nbsp;&nbsp;' + event.remarks;
                                    html += '</li>';
                                });
                                html += '</ul>';
                            }
                            
                            html += '<button type="button" class="button button-small" id="show-acs-raw-details" style="margin-top: 10px;">Show Raw Response</button>';
                            
                            $message.html(html);
                            $result.find('.notice').removeClass('notice-error').addClass('notice-success');
                            $result.show();
                            
                            // Store raw response for details view
                            $details.data('raw-response', JSON.stringify(data.raw_response || {}, null, 2));
                            
                            // Toggle raw details
                            $('#show-acs-raw-details').on('click', function() {
                                if ($details.is(':visible')) {
                                    $details.hide();
                                    $(this).text('Show Raw Response');
                                } else {
                                    $details.text($details.data('raw-response')).show();
                                    $(this).text('Hide Raw Response');
                                }
                            });
                        } else {
                            var errorMsg = '<strong>✗ Error:</strong> ' + (response.data.message || 'Unknown error');
                            if (response.data.error_code) {
                                errorMsg += '<br><strong>Error Code:</strong> ' + response.data.error_code;
                            }
                            $message.html(errorMsg);
                            $result.find('.notice').removeClass('notice-success').addClass('notice-error');
                            $result.show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $message.html('<strong>✗ Request Failed:</strong> ' + error);
                        $result.find('.notice').removeClass('notice-success').addClass('notice-error');
                        $result.show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Test Voucher');
                    }
                });
            });
            
            // Allow Enter key to trigger ACS test
            $('#courier_acs_test_voucher').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#courier_acs_test_voucher_btn').click();
                }
            });
            
            // Sync all orders functionality
            $('#sync-all-orders').on('click', function() {
                var $button = $(this);
                var $status = $('#sync-all-orders-status');
                var $progress = $('#sync-all-orders-progress');
                var $progressFill = $progress.find('.progress-bar-fill');
                var $progressText = $('#sync-progress-text');
                
                if (!confirm('This will sync all orders to the dashboard. This may take a while. Continue?')) {
                    return;
                }
                
                $button.prop('disabled', true).text('Syncing...');
                $status.html('');
                $progress.show();
                $progressFill.css('width', '0%');
                $progressText.text('Starting...');
                
                var offset = 0;
                var limit = 50; // Process 50 orders at a time
                var totalSynced = 0;
                var totalFailed = 0;
                var totalProcessed = 0;
                
                function syncBatch() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'courier_intelligence_sync_all_orders',
                            nonce: '<?php echo wp_create_nonce('courier_intelligence_sync_all'); ?>',
                            limit: limit,
                            offset: offset
                        },
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;
                                totalSynced += data.synced;
                                totalFailed += data.failed;
                                totalProcessed += data.total;
                                
                                var progress = data.has_more ? Math.min(95, (totalProcessed / 1000) * 100) : 100;
                                $progressFill.css('width', progress + '%');
                                $progressText.text('Synced: ' + totalSynced + ' | Failed: ' + totalFailed + ' | Processed: ' + totalProcessed);
                                
                                if (data.has_more) {
                                    offset += limit;
                                    setTimeout(syncBatch, 500); // Small delay to avoid overwhelming the server
                                } else {
                                    // Finished
                                    $button.prop('disabled', false).text('Sync All Orders');
                                    $progressFill.css('width', '100%');
                                    $progressText.text('Completed! Synced: ' + totalSynced + ' | Failed: ' + totalFailed);
                                    
                                    if (totalSynced > 0) {
                                        $status.html('<span style="color: #46b450;">✓ ' + totalSynced + ' orders synced successfully</span>');
                                    }
                                    if (totalFailed > 0) {
                                        $status.html('<span style="color: #dc3232;">✗ ' + totalFailed + ' orders failed to sync</span>');
                                    }
                                }
                            } else {
                                $button.prop('disabled', false).text('Sync All Orders');
                                $progress.hide();
                                $status.html('<span style="color: #dc3232;">Error: ' + (response.data.message || 'Unknown error') + '</span>');
                            }
                        },
                        error: function() {
                            $button.prop('disabled', false).text('Sync All Orders');
                            $progress.hide();
                            $status.html('<span style="color: #dc3232;">Failed to sync orders. Please try again.</span>');
                        }
                    });
                }
                
                syncBatch();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render logs page
     */
    public function render_logs_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Handle filters
        $filters = array();
        if (isset($_GET['filter_type']) && !empty($_GET['filter_type'])) {
            $filters['type'] = sanitize_text_field($_GET['filter_type']);
        }
        if (isset($_GET['filter_status']) && !empty($_GET['filter_status'])) {
            $filters['status'] = sanitize_text_field($_GET['filter_status']);
        }
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $filters['search'] = sanitize_text_field($_GET['search']);
        }
        
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        
        $log_data = Courier_Intelligence_Logger::get_paginated_logs($page, $per_page, $filters);
        $stats = Courier_Intelligence_Logger::get_stats();
        
        ?>
        <div class="wrap">
            <h1>Courier Intelligence - Activity Logs</h1>
            
            <?php if (isset($_GET['cleared']) && $_GET['cleared'] == '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Logs cleared successfully.</p>
                </div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="courier-intelligence-stats" style="margin: 20px 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <div class="stat-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <strong>Total Logs</strong>
                    <div style="font-size: 24px; margin-top: 5px;"><?php echo number_format($stats['total']); ?></div>
                </div>
                <div class="stat-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <strong>Orders Success</strong>
                    <div style="font-size: 24px; margin-top: 5px; color: #46b450;"><?php echo number_format($stats['orders_success']); ?></div>
                </div>
                <div class="stat-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <strong>Orders Error</strong>
                    <div style="font-size: 24px; margin-top: 5px; color: #dc3232;"><?php echo number_format($stats['orders_error']); ?></div>
                </div>
                <div class="stat-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <strong>Vouchers Success</strong>
                    <div style="font-size: 24px; margin-top: 5px; color: #46b450;"><?php echo number_format($stats['vouchers_success']); ?></div>
                </div>
                <div class="stat-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <strong>Vouchers Error</strong>
                    <div style="font-size: 24px; margin-top: 5px; color: #dc3232;"><?php echo number_format($stats['vouchers_error']); ?></div>
                </div>
                <div class="stat-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <strong>Vouchers Debug</strong>
                    <div style="font-size: 24px; margin-top: 5px; color: #0073aa;"><?php echo number_format($stats['vouchers_debug'] ?? 0); ?></div>
                </div>
                <div class="stat-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                    <strong>Last 24h</strong>
                    <div style="font-size: 24px; margin-top: 5px;"><?php echo number_format($stats['last_24h']); ?></div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="courier-intelligence-filters" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                    <input type="hidden" name="page" value="courier-intelligence-logs">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="filter_type">Type</label>
                            </th>
                            <td>
                                <select name="filter_type" id="filter_type">
                                    <option value="">All Types</option>
                                    <option value="order" <?php selected($filters['type'] ?? '', 'order'); ?>>Orders</option>
                                    <option value="voucher" <?php selected($filters['type'] ?? '', 'voucher'); ?>>Vouchers</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="filter_status">Status</label>
                            </th>
                            <td>
                                <select name="filter_status" id="filter_status">
                                    <option value="">All Statuses</option>
                                    <option value="success" <?php selected($filters['status'] ?? '', 'success'); ?>>Success</option>
                                    <option value="error" <?php selected($filters['status'] ?? '', 'error'); ?>>Error</option>
                                    <option value="debug" <?php selected($filters['status'] ?? '', 'debug'); ?>>Debug</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="search">Search</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="search" 
                                       id="search" 
                                       value="<?php echo esc_attr($filters['search'] ?? ''); ?>" 
                                       placeholder="Order ID, message, error..." 
                                       class="regular-text" />
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Filter Logs">
                        <a href="<?php echo admin_url('admin.php?page=courier-intelligence-logs'); ?>" class="button">Reset</a>
                    </p>
                </form>
            </div>
            
            <!-- Logs Table -->
            <div class="courier-intelligence-logs" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 style="margin: 0;">Logs (<?php echo number_format($log_data['total']); ?> total)</h2>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 0;">
                        <?php wp_nonce_field('courier_intelligence_clear_logs'); ?>
                        <input type="hidden" name="action" value="courier_intelligence_clear_logs">
                        <input type="submit" class="button button-secondary" value="Clear All Logs" onclick="return confirm('Are you sure you want to clear all logs? This cannot be undone.');">
                    </form>
                </div>
                
                <?php if (empty($log_data['logs'])): ?>
                    <p>No logs found.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 150px;">Timestamp</th>
                                <th style="width: 80px;">Type</th>
                                <th style="width: 80px;">Status</th>
                                <th style="width: 120px;">Order ID</th>
                                <th>Message</th>
                                <th style="width: 100px;">HTTP Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log_data['logs'] as $log): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        $timestamp = strtotime($log['timestamp']);
                                        echo date('Y-m-d H:i:s', $timestamp);
                                        ?>
                                    </td>
                                    <td>
                                        <span class="dashicons dashicons-<?php echo $log['type'] === 'order' ? 'cart' : 'tickets-alt'; ?>" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                        <?php echo esc_html(ucfirst($log['type'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($log['status'] === 'success'): ?>
                                            <span style="color: #46b450; font-weight: bold;">✓ Success</span>
                                        <?php elseif ($log['status'] === 'debug'): ?>
                                            <span style="color: #0073aa; font-weight: bold;">🔍 Debug</span>
                                        <?php else: ?>
                                            <span style="color: #dc3232; font-weight: bold;">✗ Error</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['external_order_id']): ?>
                                            <code><?php echo esc_html($log['external_order_id']); ?></code>
                                        <?php else: ?>
                                            <em>N/A</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;">
                                            <?php if ($log['type'] === 'order'): ?>
                                                <span style="color: #0073aa;">📦 Order:</span>
                                            <?php elseif ($log['type'] === 'voucher'): ?>
                                                <span style="color: #d63638;">🎫 Voucher:</span>
                                            <?php endif; ?>
                                            <?php echo esc_html($log['message']); ?>
                                        </div>
                                        <?php if (!empty($log['error_message'])): ?>
                                            <br><small style="color: #dc3232;"><strong>Error:</strong> <?php echo esc_html($log['error_message']); ?></small>
                                        <?php endif; ?>
                                        
                                        <!-- Order Details (show for all order logs, not just success) -->
                                        <?php if ($log['type'] === 'order' && (!empty($log['customer_email']) || !empty($log['total_amount']) || !empty($log['order_status']))): ?>
                                            <div style="margin-top: 5px; padding: 5px; background: #e8f4f8; border-left: 3px solid #0073aa; font-size: 11px;">
                                                <?php if (!empty($log['customer_email'])): ?>
                                                    <strong>Customer Email:</strong> <?php echo esc_html($log['customer_email']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($log['total_amount'])): ?>
                                                    <strong>Amount:</strong> <?php echo esc_html($log['total_amount']); ?> <?php echo esc_html($log['currency'] ?? ''); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($log['order_status'])): ?>
                                                    <strong>Order Status:</strong> <?php echo esc_html($log['order_status']); ?><br>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Voucher Details (show for all voucher logs, not just success) -->
                                        <?php if ($log['type'] === 'voucher' && (!empty($log['voucher_number']) || !empty($log['courier_name']) || !empty($log['order_status']))): ?>
                                            <div style="margin-top: 5px; padding: 5px; background: #fef7f1; border-left: 3px solid #d63638; font-size: 11px;">
                                                <?php if (!empty($log['voucher_number'])): ?>
                                                    <strong>Voucher/Tracking Number:</strong> <code style="background: #fff; padding: 2px 4px; border-radius: 2px;"><?php echo esc_html($log['voucher_number']); ?></code><br>
                                                <?php endif; ?>
                                                <?php if (!empty($log['courier_name'])): ?>
                                                    <strong>Courier:</strong> <?php echo esc_html($log['courier_name']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($log['order_status'])): ?>
                                                    <strong>Status:</strong> <?php echo esc_html($log['order_status']); ?><br>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Debug Info (show for debug status) -->
                                        <?php if ($log['status'] === 'debug'): ?>
                                            <div style="margin-top: 5px; padding: 5px; background: #f0f8ff; border-left: 3px solid #0073aa; font-size: 11px;">
                                                <?php if (!empty($log['meta_key'])): ?>
                                                    <strong>Meta Key:</strong> <code><?php echo esc_html($log['meta_key']); ?></code><br>
                                                <?php endif; ?>
                                                <?php if (!empty($log['tracking_number'])): ?>
                                                    <strong>Tracking Number:</strong> <?php echo esc_html($log['tracking_number']); ?><br>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($log['payload_preview'])): ?>
                                            <br><small style="color: #0073aa;"><strong>📤 Payload:</strong> <em>Click "Show/Hide" below to view</em></small>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($log['response_body']) && strlen($log['response_body']) < 200): ?>
                                            <br><small style="color: #666;"><strong>Response:</strong> <?php echo esc_html($log['response_body']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['http_status']): ?>
                                            <code><?php echo esc_html($log['http_status']); ?></code>
                                        <?php else: ?>
                                            <em>-</em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($log['error_message']) || !empty($log['response_body']) || !empty($log['payload_preview']) || !empty($log['url']) || !empty($log['error_code']) || !empty($log['customer_email']) || !empty($log['voucher_number']) || !empty($log['courier_name']) || !empty($log['meta_key']) || !empty($log['tracking_number'])): ?>
                                    <tr style="background: #f9f9f9;">
                                        <td colspan="6" style="padding-left: 30px; font-size: 12px; color: #666;">
                                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px;">
                                                <div style="flex: 1;">
                                                    <?php if (!empty($log['url'])): ?>
                                                        <strong>URL:</strong> <code><?php echo esc_html($log['url']); ?></code><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($log['error_code'])): ?>
                                                        <strong>Error Code:</strong> <code><?php echo esc_html($log['error_code']); ?></code><br>
                                                    <?php endif; ?>
                                                    <?php if ($log['status'] === 'success' && !empty($log['http_status'])): ?>
                                                        <strong>HTTP Status:</strong> <code style="color: #46b450;"><?php echo esc_html($log['http_status']); ?></code><br>
                                                    <?php endif; ?>
                                                </div>
                                                <button type="button" 
                                                        class="button button-small copy-error-btn" 
                                                        data-log-id="<?php echo esc_attr($log['id']); ?>"
                                                        style="margin-left: 10px;"
                                                        title="Copy log details">
                                                    <span class="dashicons dashicons-clipboard" style="font-size: 16px; width: 16px; height: 16px;"></span> Copy Details
                                                </button>
                                            </div>
                                            
                                            <?php if (!empty($log['payload_preview'])): ?>
                                                <div style="margin: 10px 0;">
                                                    <strong>📤 Payload Sent (<?php echo $log['type'] === 'order' ? 'Order Data' : 'Voucher Data'; ?>):</strong>
                                                    <button type="button" 
                                                            class="button button-small toggle-payload-btn" 
                                                            data-target="payload-<?php echo esc_attr($log['id']); ?>"
                                                            style="margin-left: 10px; padding: 2px 8px; height: auto; line-height: 1.4; font-size: 11px;">
                                                        <span class="dashicons dashicons-arrow-down-alt2" style="font-size: 12px; width: 12px; height: 12px;"></span> Show/Hide
                                                    </button>
                                                    <pre id="payload-<?php echo esc_attr($log['id']); ?>" style="display: none; max-height: 300px; overflow: auto; margin: 5px 0; font-size: 11px; background: #f5f5f5; padding: 12px; border-radius: 3px; border: 1px solid #ddd; white-space: pre-wrap; word-wrap: break-word;"><?php 
                                                        // Try to format JSON if possible
                                                        $payload = $log['payload_preview'];
                                                        $json_start = strpos($payload, '{');
                                                        if ($json_start !== false) {
                                                            $json_part = substr($payload, $json_start);
                                                            $decoded = json_decode($json_part, true);
                                                            if ($decoded !== null) {
                                                                echo esc_html(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                                            } else {
                                                                echo esc_html($payload);
                                                            }
                                                        } else {
                                                            echo esc_html($payload);
                                                        }
                                                    ?></pre>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($log['response_body'])): ?>
                                                <div style="margin: 10px 0;">
                                                    <strong>📥 API Response:</strong>
                                                    <button type="button" 
                                                            class="button button-small toggle-payload-btn" 
                                                            data-target="response-<?php echo esc_attr($log['id']); ?>"
                                                            style="margin-left: 10px; padding: 2px 8px; height: auto; line-height: 1.4; font-size: 11px;">
                                                        <span class="dashicons dashicons-arrow-down-alt2" style="font-size: 12px; width: 12px; height: 12px;"></span> Show/Hide
                                                    </button>
                                                    <pre id="response-<?php echo esc_attr($log['id']); ?>" style="display: none; max-height: 200px; overflow: auto; margin: 5px 0; font-size: 11px; background: #f0f8ff; padding: 12px; border-radius: 3px; border: 1px solid #b3d9ff; white-space: pre-wrap; word-wrap: break-word;"><?php 
                                                        // Try to format JSON response if possible
                                                        $response = $log['response_body'];
                                                        $decoded = json_decode($response, true);
                                                        if ($decoded !== null) {
                                                            echo esc_html(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                                        } else {
                                                            echo esc_html($response);
                                                        }
                                                    ?></pre>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <textarea id="error-full-<?php echo esc_attr($log['id']); ?>" style="display: none;"><?php
                                                $error_text = "=== Courier Intelligence Log ===\n\n";
                                                $error_text .= "Timestamp: " . $log['timestamp'] . "\n";
                                                $error_text .= "Type: " . $log['type'] . "\n";
                                                $error_text .= "Status: " . $log['status'] . "\n";
                                                $error_text .= "Message: " . $log['message'] . "\n\n";
                                                
                                                if (!empty($log['external_order_id'])) {
                                                    $error_text .= "Order ID: " . $log['external_order_id'] . "\n";
                                                }
                                                if (!empty($log['order_id'])) {
                                                    $error_text .= "Internal Order ID: " . $log['order_id'] . "\n";
                                                }
                                                
                                                // Order-specific fields
                                                if ($log['type'] === 'order') {
                                                    if (!empty($log['customer_email'])) {
                                                        $error_text .= "Customer Email: " . $log['customer_email'] . "\n";
                                                    }
                                                    if (!empty($log['total_amount'])) {
                                                        $error_text .= "Total Amount: " . $log['total_amount'] . " " . ($log['currency'] ?? '') . "\n";
                                                    }
                                                    if (!empty($log['order_status'])) {
                                                        $error_text .= "Order Status: " . $log['order_status'] . "\n";
                                                    }
                                                }
                                                
                                                // Voucher-specific fields
                                                if ($log['type'] === 'voucher') {
                                                    if (!empty($log['voucher_number'])) {
                                                        $error_text .= "Voucher/Tracking Number: " . $log['voucher_number'] . "\n";
                                                    }
                                                    if (!empty($log['courier_name'])) {
                                                        $error_text .= "Courier Name: " . $log['courier_name'] . "\n";
                                                    }
                                                    if (!empty($log['order_status'])) {
                                                        $error_text .= "Status: " . $log['order_status'] . "\n";
                                                    }
                                                }
                                                
                                                // Debug fields
                                                if (!empty($log['meta_key'])) {
                                                    $error_text .= "Meta Key: " . $log['meta_key'] . "\n";
                                                }
                                                if (!empty($log['tracking_number'])) {
                                                    $error_text .= "Tracking Number: " . $log['tracking_number'] . "\n";
                                                }
                                                
                                                // Error details
                                                if (!empty($log['error_code'])) {
                                                    $error_text .= "\nError Code: " . $log['error_code'] . "\n";
                                                }
                                                if (!empty($log['error_message'])) {
                                                    $error_text .= "Error Message: " . $log['error_message'] . "\n";
                                                }
                                                
                                                // Request details
                                                if (!empty($log['url'])) {
                                                    $error_text .= "\nRequest URL: " . $log['url'] . "\n";
                                                }
                                                if (!empty($log['http_status'])) {
                                                    $error_text .= "HTTP Status: " . $log['http_status'] . "\n";
                                                }
                                                
                                                // Payload
                                                if (!empty($log['payload_preview'])) {
                                                    $error_text .= "\n=== Payload Sent ===\n" . $log['payload_preview'] . "\n";
                                                }
                                                
                                                // Response
                                                if (!empty($log['response_body'])) {
                                                    $error_text .= "\n=== API Response ===\n" . $log['response_body'] . "\n";
                                                }
                                                
                                                echo esc_textarea($error_text);
                                            ?></textarea>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($log_data['pages'] > 1): ?>
                        <div class="tablenav">
                            <div class="tablenav-pages">
                                <?php
                                $page_url = admin_url('admin.php?page=courier-intelligence-logs');
                                if (!empty($filters['type'])) {
                                    $page_url = add_query_arg('filter_type', $filters['type'], $page_url);
                                }
                                if (!empty($filters['status'])) {
                                    $page_url = add_query_arg('filter_status', $filters['status'], $page_url);
                                }
                                if (!empty($filters['search'])) {
                                    $page_url = add_query_arg('search', $filters['search'], $page_url);
                                }
                                
                                echo paginate_links(array(
                                    'base' => add_query_arg('paged', '%#%', $page_url),
                                    'format' => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'total' => $log_data['pages'],
                                    'current' => $page,
                                ));
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {

          // --- Helper για "Copied!" feedback ---
          function showCopiedState(btn) {
            var originalHtml = btn.getAttribute('data-original-html');
            btn.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;color:#46b450;"></span> Copied!';
            btn.disabled = true;

            setTimeout(function () {
              btn.innerHTML = originalHtml;
              btn.disabled = false;
            }, 2000);
          }

          // --- Fallback copy (χωρίς jQuery, χωρίς hidden textarea) ---
          function fallbackCopy(text, btn) {
            var temp = document.createElement('textarea');
            temp.setAttribute('readonly', '');
            temp.style.position = 'absolute';
            temp.style.left = '-9999px';
            temp.style.top = '-9999px';
            temp.value = text;

            document.body.appendChild(temp);
            temp.select();

            try {
              var ok = document.execCommand('copy');
              if (ok) {
                showCopiedState(btn);
              } else {
                alert('Failed to copy. Please select and copy manually.');
              }
            } catch (e) {
              console.error('Fallback copy failed:', e);
              alert('Failed to copy. Please select and copy manually.');
            }

            document.body.removeChild(temp);
          }

          // --- COPY DETAILS buttons ---
          var copyButtons = document.querySelectorAll('.copy-error-btn');
          copyButtons.forEach(function (btn) {
            // αποθηκεύουμε το αρχικό HTML στο data attribute
            btn.setAttribute('data-original-html', btn.innerHTML);

            btn.addEventListener('click', function () {
              var logId = btn.getAttribute('data-log-id');
              var textarea = document.getElementById('error-full-' + logId);

              if (!textarea) {
                console.warn('No textarea found for log', logId);
                return;
              }

              var textToCopy = textarea.value || '';

              if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy)
                  .then(function () {
                    showCopiedState(btn);
                  })
                  .catch(function (err) {
                    console.error('Clipboard API failed, using fallback:', err);
                    fallbackCopy(textToCopy, btn);
                  });
              } else {
                fallbackCopy(textToCopy, btn);
              }
            });
          });

          // --- SHOW / HIDE payload & response ---
          var toggleButtons = document.querySelectorAll('.toggle-payload-btn');
          toggleButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
              var targetId = btn.getAttribute('data-target');
              var target = document.getElementById(targetId);
              var icon = btn.querySelector('.dashicons');

              if (!target) {
                console.warn('No target element for', targetId);
                return;
              }

              // απλό toggle χωρίς jQuery
              if (target.style.display === '' || target.style.display === 'none') {
                target.style.display = 'block';
                if (icon) {
                  icon.classList.remove('dashicons-arrow-down-alt2');
                  icon.classList.add('dashicons-arrow-up-alt2');
                }
              } else {
                target.style.display = 'none';
                if (icon) {
                  icon.classList.remove('dashicons-arrow-up-alt2');
                  icon.classList.add('dashicons-arrow-down-alt2');
                }
              }
            });
          });

        });
        </script>
        <?php
    }
}

