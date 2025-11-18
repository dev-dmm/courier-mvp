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
        if ($hook !== 'toplevel_page_courier-intelligence') {
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
}

