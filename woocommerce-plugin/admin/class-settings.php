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
        if ($hook !== 'toplevel_page_courier-intelligence' && $hook !== 'courier-intelligence_page_courier-intelligence-logs') {
            return;
        }
        
        wp_enqueue_script('jquery');
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['api_endpoint'])) {
            $sanitized['api_endpoint'] = esc_url_raw($input['api_endpoint']);
        }
        
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['api_secret'])) {
            $sanitized['api_secret'] = sanitize_text_field($input['api_secret']);
        }
        
        if (isset($input['courier_name'])) {
            $sanitized['courier_name'] = sanitize_text_field($input['courier_name']);
        }
        
        if (isset($input['voucher_meta_key'])) {
            $sanitized['voucher_meta_key'] = sanitize_text_field($input['voucher_meta_key']);
        }
        
        return $sanitized;
    }
    
    /**
     * Scan orders for meta keys (AJAX handler)
     */
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
        
        ?>
        <div class="wrap">
            <h1>Courier Intelligence Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('courier_intelligence_settings'); ?>
                
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
                            <p class="description">Your API key from the Courier Intelligence platform</p>
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
                            <p class="description">Your API secret for HMAC signing</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="courier_name">Courier Name</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="courier_name" 
                                   name="courier_intelligence_settings[courier_name]" 
                                   value="<?php echo esc_attr($settings['courier_name'] ?? ''); ?>" 
                                   class="regular-text" 
                                   placeholder="e.g., ACS, DHL, FedEx" />
                            <p class="description">The name of your courier service (e.g., ACS, DHL, FedEx)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="voucher_meta_key">Voucher/Tracking Meta Key</label>
                        </th>
                        <td>
                            <select id="voucher_meta_key" 
                                    name="courier_intelligence_settings[voucher_meta_key]" 
                                    class="regular-text">
                                <option value="">-- Select Meta Key --</option>
                                <?php foreach ($meta_keys as $key => $count): ?>
                                    <option value="<?php echo esc_attr($key); ?>" 
                                            <?php selected($settings['voucher_meta_key'] ?? '', $key); ?>>
                                        <?php echo esc_html($key); ?> (found in <?php echo $count; ?> order<?php echo $count !== 1 ? 's' : ''; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Select which order meta key contains the voucher/tracking number.
                                <button type="button" id="scan-meta-keys" class="button button-secondary" style="margin-left: 10px;">
                                    Scan Orders for Meta Keys
                                </button>
                            </p>
                            <div id="meta-keys-scan-result" style="margin-top: 10px; display: none;"></div>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#scan-meta-keys').on('click', function() {
                var $button = $(this);
                var $result = $('#meta-keys-scan-result');
                var $select = $('#voucher_meta_key');
                
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
                            var options = '<option value="">-- Select Meta Key --</option>';
                            
                            $.each(data.meta_keys, function(key, count) {
                                var selected = $select.val() === key ? ' selected' : '';
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
                        $button.prop('disabled', false).text('Scan Orders for Meta Keys');
                    }
                });
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
                                        <?php echo esc_html($log['message']); ?>
                                        <?php if (!empty($log['error_message'])): ?>
                                            <br><small style="color: #dc3232;"><?php echo esc_html($log['error_message']); ?></small>
                                        <?php endif; ?>
                                        
                                        <?php if ($log['status'] === 'success' && $log['type'] === 'order'): ?>
                                            <?php if (!empty($log['customer_email'])): ?>
                                                <br><small style="color: #666;"><strong>Customer:</strong> <?php echo esc_html($log['customer_email']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($log['total_amount'])): ?>
                                                <br><small style="color: #666;"><strong>Amount:</strong> <?php echo esc_html($log['total_amount']); ?> <?php echo esc_html($log['currency'] ?? ''); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($log['status'])): ?>
                                                <br><small style="color: #666;"><strong>Status:</strong> <?php echo esc_html($log['status']); ?></small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($log['status'] === 'success' && $log['type'] === 'voucher'): ?>
                                            <?php if (!empty($log['voucher_number'])): ?>
                                                <br><small style="color: #666;"><strong>Voucher:</strong> <code><?php echo esc_html($log['voucher_number']); ?></code></small>
                                            <?php endif; ?>
                                            <?php if (!empty($log['courier_name'])): ?>
                                                <br><small style="color: #666;"><strong>Courier:</strong> <?php echo esc_html($log['courier_name']); ?></small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($log['status'] === 'debug' && !empty($log['meta_key'])): ?>
                                            <br><small style="color: #0073aa;"><strong>Meta Key:</strong> <code><?php echo esc_html($log['meta_key']); ?></code></small>
                                        <?php endif; ?>
                                        <?php if ($log['status'] === 'debug' && !empty($log['tracking_number'])): ?>
                                            <br><small style="color: #0073aa;"><strong>Tracking:</strong> <?php echo esc_html($log['tracking_number']); ?></small>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($log['payload_preview'])): ?>
                                            <br><small style="color: #666;"><strong>Payload:</strong> <code style="font-size: 10px; word-break: break-all;"><?php echo esc_html($log['payload_preview']); ?></code></small>
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
                                <?php if (!empty($log['error_message']) || !empty($log['response_body']) || !empty($log['payload_preview']) || $log['status'] === 'success'): ?>
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
                                                <strong>Payload:</strong> 
                                                <pre id="payload-<?php echo esc_attr($log['id']); ?>" style="max-height: 150px; overflow: auto; margin: 5px 0; font-size: 11px; background: #f5f5f5; padding: 8px; border-radius: 3px;"><?php echo esc_html($log['payload_preview']); ?></pre>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($log['response_body']) && strlen($log['response_body']) >= 200): ?>
                                                <strong>Response:</strong> 
                                                <pre id="error-response-<?php echo esc_attr($log['id']); ?>" style="max-height: 100px; overflow: auto; margin: 5px 0;"><?php echo esc_html($log['response_body']); ?></pre>
                                            <?php endif; ?>
                                            
                                            <textarea id="error-full-<?php echo esc_attr($log['id']); ?>" style="display: none;"><?php
                                                $error_text = "Courier Intelligence Log\n";
                                                $error_text .= "Timestamp: " . $log['timestamp'] . "\n";
                                                $error_text .= "Type: " . $log['type'] . "\n";
                                                $error_text .= "Status: " . $log['status'] . "\n";
                                                if (!empty($log['external_order_id'])) {
                                                    $error_text .= "Order ID: " . $log['external_order_id'] . "\n";
                                                }
                                                $error_text .= "Message: " . $log['message'] . "\n";
                                                if (!empty($log['url'])) {
                                                    $error_text .= "URL: " . $log['url'] . "\n";
                                                }
                                                if (!empty($log['http_status'])) {
                                                    $error_text .= "HTTP Status: " . $log['http_status'] . "\n";
                                                }
                                                if (!empty($log['error_code'])) {
                                                    $error_text .= "Error Code: " . $log['error_code'] . "\n";
                                                }
                                                if (!empty($log['error_message'])) {
                                                    $error_text .= "Error Message: " . $log['error_message'] . "\n";
                                                }
                                                if ($log['type'] === 'order' && !empty($log['customer_email'])) {
                                                    $error_text .= "Customer Email: " . $log['customer_email'] . "\n";
                                                }
                                                if ($log['type'] === 'order' && !empty($log['total_amount'])) {
                                                    $error_text .= "Total Amount: " . $log['total_amount'] . " " . ($log['currency'] ?? '') . "\n";
                                                }
                                                if ($log['type'] === 'voucher' && !empty($log['voucher_number'])) {
                                                    $error_text .= "Voucher Number: " . $log['voucher_number'] . "\n";
                                                }
                                                if ($log['type'] === 'voucher' && !empty($log['courier_name'])) {
                                                    $error_text .= "Courier Name: " . $log['courier_name'] . "\n";
                                                }
                                                if (!empty($log['payload_preview'])) {
                                                    $error_text .= "\nPayload:\n" . $log['payload_preview'];
                                                }
                                                if (!empty($log['response_body'])) {
                                                    $error_text .= "\n\nResponse Body:\n" . $log['response_body'];
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
        jQuery(document).ready(function($) {
            // Copy error button functionality
            $('.copy-error-btn').on('click', function() {
                var $btn = $(this);
                var logId = $btn.data('log-id');
                var $textarea = $('#error-full-' + logId);
                
                if ($textarea.length) {
                    $textarea.select();
                    document.execCommand('copy');
                    
                    // Visual feedback
                    var originalText = $btn.html();
                    $btn.html('<span class="dashicons dashicons-yes" style="font-size: 16px; width: 16px; height: 16px; color: #46b450;"></span> Copied!');
                    $btn.prop('disabled', true);
                    
                    setTimeout(function() {
                        $btn.html(originalText);
                        $btn.prop('disabled', false);
                    }, 2000);
                }
            });
        });
        </script>
        <?php
    }
}

