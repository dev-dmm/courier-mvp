<?php
/**
 * Plugin Name: Courier Intelligence
 * Plugin URI: https://example.com/courier-intelligence
 * Description: Send order and voucher data to Courier Intelligence platform for delivery risk analysis
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: courier-intelligence
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('COURIER_INTELLIGENCE_VERSION', '1.0.0');
define('COURIER_INTELLIGENCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('COURIER_INTELLIGENCE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-api-client.php';
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-hmac-signer.php';
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-logger.php';
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-customer-hasher.php';
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'admin/class-settings.php';

/**
 * Main plugin class
 */
class Courier_Intelligence {
    
    private static $instance = null;
    private $api_client;
    private $hmac_signer;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api_client = new Courier_Intelligence_API_Client();
        $this->hmac_signer = new Courier_Intelligence_HMAC_Signer();
        
        // Initialize admin settings
        if (is_admin()) {
            new Courier_Intelligence_Settings();
        }
        
        // Hook into WooCommerce order events
        add_action('woocommerce_order_status_completed', array($this, 'send_order_data'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'send_order_data'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array($this, 'send_order_data'), 10, 1);
        add_action('woocommerce_order_meta_updated', array($this, 'check_for_tracking_update'), 10, 1);
        
        // Add voucher column to orders list
        add_filter('manage_edit-shop_order_columns', array($this, 'add_voucher_column_header'), 20);
        add_action('manage_shop_order_posts_custom_column', array($this, 'add_voucher_column_content'), 10, 2);
        
        // Support for HPOS (High-Performance Order Storage) - WooCommerce 8.0+
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_voucher_column_header'), 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'add_voucher_column_content_hpos'), 10, 2);
        
        // Add Oreksi Risk column to orders list
        add_filter('manage_edit-shop_order_columns', array($this, 'add_oreksi_risk_column_header'), 25);
        add_action('manage_shop_order_posts_custom_column', array($this, 'add_oreksi_risk_column_content'), 10, 2);
        
        // Support for HPOS - Oreksi Risk column
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_oreksi_risk_column_header'), 25);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'add_oreksi_risk_column_content_hpos'), 10, 2);
        
        // Add CSS for Oreksi Risk column
        add_action('admin_head', array($this, 'add_oreksi_risk_styles'));
    }
    
    /**
     * Send order data to API when order status changes
     */
    public function send_order_data($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            Courier_Intelligence_Logger::log('order', 'error', array(
                'order_id' => $order_id,
                'message' => 'Order not found',
                'error_code' => 'order_not_found',
                'error_message' => 'Order with ID ' . $order_id . ' not found',
            ));
            return;
        }
        
        $settings = get_option('courier_intelligence_settings');
        
        if (empty($settings['api_endpoint']) || empty($settings['api_key']) || empty($settings['api_secret'])) {
            Courier_Intelligence_Logger::log('order', 'error', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'API settings not configured - order not sent',
                'error_code' => 'missing_settings',
                'error_message' => 'API settings not configured',
            ));
            return; // Settings not configured
        }
        
        $current_status = $order->get_status();
        $existing_score = $order->get_meta('_oreksi_risk_score');
        
        // Always send if no score exists, regardless of status
        // For cancelled orders, always send to update risk score
        if ($current_status === 'cancelled' || '' === $existing_score || null === $existing_score || $existing_score === false) {
            // Proceed to send
        } else {
            // Score exists and not cancelled, skip
            return;
        }
        
        $order_data = $this->prepare_order_data($order);
        
        $this->api_client->send_order($order_data, $order_id);
    }
    
    /**
     * Maybe send order to API if it doesn't have a risk score yet
     * Called when rendering the orders list to auto-fetch missing scores
     * 
     * @param \WC_Order $order
     * @return void
     */
    private function maybe_send_order_for_score($order) {
        // Check if we already tried to send this order recently (avoid spam)
        $last_attempt = $order->get_meta('_oreksi_score_fetch_attempt');
        $now = time();
        
        // Only try once per hour to avoid too many API calls
        if ($last_attempt && ($now - intval($last_attempt)) < 3600) {
            return;
        }
        
        // Mark that we're attempting to fetch score
        $order->update_meta_data('_oreksi_score_fetch_attempt', $now);
        $order->save();
        
        // Send order data to API
        $this->send_order_data($order->get_id());
    }
    
    /**
     * Check for tracking number updates
     */
    public function check_for_tracking_update($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'order_id' => $order_id,
                'message' => 'Order not found when checking for tracking update',
                'error_code' => 'order_not_found',
                'error_message' => 'Order with ID ' . $order_id . ' not found',
            ));
            return;
        }
        
        // Debug: Log that hook was triggered
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'external_order_id' => (string) $order->get_id(),
            'message' => 'check_for_tracking_update triggered',
        ));
        
        $settings = get_option('courier_intelligence_settings');
        $voucher_meta_key = $settings['voucher_meta_key'] ?? '_tracking_number';
        
        // If no meta key is configured, use default
        if (empty($voucher_meta_key)) {
            $voucher_meta_key = '_tracking_number';
        }
        
        // Check if tracking number exists in order meta using the configured meta key
        $tracking_number = $order->get_meta($voucher_meta_key);
        
        // Debug: Log what we found
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'external_order_id' => (string) $order->get_id(),
            'message' => 'Checking tracking meta',
            'meta_key' => $voucher_meta_key,
            'tracking_number' => $tracking_number ? 'FOUND: ' . $tracking_number : 'NOT FOUND (empty or null)',
        ));
        
        if ($tracking_number) {
            $this->send_voucher_data($order, $tracking_number);
        } else {
            // Debug: Log when tracking number is not found
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'Tracking number not found - voucher not sent',
                'meta_key_used' => $voucher_meta_key,
                'error_code' => 'tracking_not_found',
            ));
        }
    }
    
    /**
     * Send voucher/tracking data to API
     */
    public function send_voucher_data($order, $tracking_number) {
        $settings = get_option('courier_intelligence_settings');
        
        if (empty($settings['api_endpoint']) || empty($settings['api_key']) || empty($settings['api_secret'])) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'API settings not configured - voucher not sent',
                'error_code' => 'missing_settings',
                'error_message' => 'API settings not configured',
            ));
            return;
        }
        
        $voucher_data = $this->prepare_voucher_data($order, $tracking_number);
        
        $this->api_client->send_voucher($voucher_data);
    }
    
    /**
     * Prepare order data for API
     * 
     * GDPR Compliance: All customer PII (email, phone, name, address) is hashed
     * before transmission using a global salt. This ensures:
     * - No raw PII is sent to the API
     * - Same customer produces same hash across all shops
     * - Cross-shop matching without storing personal data
     * - Full GDPR compliance for data pooling and profiling
     */
    private function prepare_order_data($order) {
        $shipping_address = $order->get_address('shipping');
        
        // Prepare raw order data
        $raw_order_data = array(
            'external_order_id' => (string) $order->get_id(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'customer_phone' => $order->get_billing_phone(),
            'shipping_address_line1' => $shipping_address['address_1'] ?? '',
            'shipping_address_line2' => $shipping_address['address_2'] ?? '',
            'shipping_city' => $shipping_address['city'] ?? '',
            'shipping_postcode' => $shipping_address['postcode'] ?? '',
            'shipping_country' => $shipping_address['country'] ?? '',
            'total_amount' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'status' => $order->get_status(),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'shipping_method' => $this->get_shipping_method($order),
            'items_count' => $order->get_item_count(),
            'ordered_at' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'completed_at' => $order->get_date_completed() ? $order->get_date_completed()->format('Y-m-d H:i:s') : null,
            'meta' => array(
                'items' => $this->get_order_items($order),
            ),
        );
        
        // Hash all PII before sending (GDPR compliance)
        return Courier_Intelligence_Customer_Hasher::hash_order_data($raw_order_data);
    }
    
    /**
     * Prepare voucher data for API
     * 
     * GDPR Compliance: Customer email is hashed before transmission.
     */
    private function prepare_voucher_data($order, $tracking_number) {
        $settings = get_option('courier_intelligence_settings');
        $courier_name = $settings['courier_name'] ?? null;
        
        // Use configured courier name, or fall back to order meta
        if (empty($courier_name)) {
            $courier_name = $order->get_meta('_courier_name') ?: null;
        }
        
        // Prepare raw voucher data
        $raw_voucher_data = array(
            'voucher_number' => $tracking_number,
            'external_order_id' => (string) $order->get_id(),
            'customer_email' => $order->get_billing_email(),
            'courier_name' => $courier_name,
            'courier_service' => $order->get_meta('_courier_service') ?: null,
            'tracking_url' => $order->get_meta('_tracking_url') ?: null,
            'status' => $this->map_order_status_to_voucher_status($order->get_status()),
            'shipped_at' => $order->get_meta('_shipped_at') ?: null,
        );
        
        // Hash customer email (required for customer_hash)
        if (!empty($raw_voucher_data['customer_email'])) {
            $raw_voucher_data['customer_hash'] = Courier_Intelligence_Customer_Hasher::hash_email($raw_voucher_data['customer_email']);
            unset($raw_voucher_data['customer_email']);
        }
        
        return $raw_voucher_data;
    }
    
    /**
     * Get shipping method name
     */
    private function get_shipping_method($order) {
        $shipping_methods = $order->get_shipping_methods();
        if (!empty($shipping_methods)) {
            $method = reset($shipping_methods);
            return $method->get_method_title();
        }
        return null;
    }
    
    /**
     * Get order items
     */
    private function get_order_items($order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total(),
            );
        }
        return $items;
    }
    
    /**
     * Map WooCommerce order status to voucher status
     */
    private function map_order_status_to_voucher_status($wc_status) {
        $status_map = array(
            'processing' => 'created',
            'shipped' => 'shipped',
            'completed' => 'delivered',
            'refunded' => 'returned',
            'cancelled' => 'failed',
        );
        
        return $status_map[$wc_status] ?? 'created';
    }
    
    /**
     * Add voucher column header to orders list
     * 
     * @param array $columns
     * @return array
     */
    public function add_voucher_column_header($columns) {
        $new_columns = array();
        
        // Insert voucher column before "Order Total" or at the end
        foreach ($columns as $key => $value) {
            if ($key === 'order_total') {
                $new_columns['courier_voucher'] = __('Voucher/Tracking', 'courier-intelligence');
            }
            $new_columns[$key] = $value;
        }
        
        // If order_total doesn't exist, add at the end
        if (!isset($new_columns['courier_voucher'])) {
            $new_columns['courier_voucher'] = __('Voucher/Tracking', 'courier-intelligence');
        }
        
        return $new_columns;
    }
    
    /**
     * Add voucher column content for traditional post-based orders
     * 
     * @param string $column
     * @param int $post_id
     * @return void
     */
    public function add_voucher_column_content($column, $post_id) {
        if ($column !== 'courier_voucher') {
            return;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        
        $this->render_voucher_column($order);
    }
    
    /**
     * Add voucher column content for HPOS orders
     * 
     * @param string $column
     * @param \WC_Order $order
     * @return void
     */
    public function add_voucher_column_content_hpos($column, $order) {
        if ($column !== 'courier_voucher') {
            return;
        }
        
        $this->render_voucher_column($order);
    }
    
    /**
     * Add Oreksi Risk column header to orders list
     * 
     * @param array $columns
     * @return array
     */
    public function add_oreksi_risk_column_header($columns) {
        $new_columns = array();
        
        // Insert Oreksi Risk column after voucher column
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'courier_voucher') {
                $new_columns['oreksi_risk'] = __('Oreksi Risk', 'courier-intelligence');
            }
        }
        
        // If voucher column doesn't exist, add at the end
        if (!isset($new_columns['oreksi_risk'])) {
            $new_columns['oreksi_risk'] = __('Oreksi Risk', 'courier-intelligence');
        }
        
        return $new_columns;
    }
    
    /**
     * Add Oreksi Risk column content for traditional post-based orders
     * 
     * @param string $column
     * @param int $post_id
     * @return void
     */
    public function add_oreksi_risk_column_content($column, $post_id) {
        if ($column !== 'oreksi_risk') {
            return;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        
        $this->render_oreksi_risk_column($order);
    }
    
    /**
     * Add Oreksi Risk column content for HPOS orders
     * 
     * @param string $column
     * @param \WC_Order $order
     * @return void
     */
    public function add_oreksi_risk_column_content_hpos($column, $order) {
        if ($column !== 'oreksi_risk') {
            return;
        }
        
        $this->render_oreksi_risk_column($order);
    }
    
    /**
     * Render Oreksi Risk column content
     * 
     * @param \WC_Order $order
     * @return void
     */
    private function render_oreksi_risk_column($order) {
        $score = $order->get_meta('_oreksi_risk_score');
        
        if ('' === $score || null === $score || $score === false) {
            echo '—';
            return;
        }
        
        $score = intval($score);
        $badge_class = 'oreksi-risk-badge-low';
        
        if ($score >= 70) {
            $badge_class = 'oreksi-risk-badge-high';
        } elseif ($score >= 40) {
            $badge_class = 'oreksi-risk-badge-medium';
        }
        
        printf(
            '<span class="oreksi-risk-badge %s">%d/100</span>',
            esc_attr($badge_class),
            $score
        );
    }
    
    /**
     * Add CSS styles for Oreksi Risk column
     * 
     * @return void
     */
    public function add_oreksi_risk_styles() {
        $screen = get_current_screen();
        if ($screen && ($screen->id === 'edit-shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
            ?>
            <style>
                .column-oreksi_risk {
                    width: 110px;
                }
                .oreksi-risk-badge {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 999px;
                    font-size: 11px;
                    font-weight: 600;
                    line-height: 1.4;
                }
                .oreksi-risk-badge-low {
                    background: #e3f9e5;
                    color: #1b7f3b;
                }
                .oreksi-risk-badge-medium {
                    background: #fff4cc;
                    color: #8a6d1b;
                }
                .oreksi-risk-badge-high {
                    background: #ffe3e3;
                    color: #b42318;
                }
            </style>
            <?php
        }
    }
    
    /**
     * Render voucher column content
     * 
     * @param \WC_Order $order
     * @return void
     */
    private function render_voucher_column($order) {
        $settings = get_option('courier_intelligence_settings', array());
        $voucher_meta_key = $settings['voucher_meta_key'] ?? '_tracking_number';
        
        if (empty($voucher_meta_key)) {
            $voucher_meta_key = '_tracking_number';
        }
        
        $tracking_number = $order->get_meta($voucher_meta_key);
        
        if ($tracking_number) {
            // Check if it's a URL (some plugins store tracking URLs)
            if (filter_var($tracking_number, FILTER_VALIDATE_URL)) {
                echo '<a href="' . esc_url($tracking_number) . '" target="_blank" rel="noopener" style="color: #2271b1; text-decoration: underline;">';
                echo '<span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span> ';
                echo esc_html(basename(parse_url($tracking_number, PHP_URL_PATH)) ?: $tracking_number);
                echo '</a>';
            } else {
                // Display tracking number with copy button
                echo '<span style="font-family: monospace; font-size: 12px;">' . esc_html($tracking_number) . '</span>';
                echo '<button type="button" class="button button-small copy-voucher-btn" style="margin-left: 5px; padding: 2px 6px; height: auto; line-height: 1.4;" data-voucher="' . esc_attr($tracking_number) . '" title="Copy voucher number">';
                echo '<span class="dashicons dashicons-clipboard" style="font-size: 12px; width: 12px; height: 12px;"></span>';
                echo '</button>';
            }
            
            // Show courier name if configured
            $courier_name = $settings['courier_name'] ?? null;
            if ($courier_name) {
                echo '<br><small style="color: #666;">' . esc_html($courier_name) . '</small>';
            }
        } else {
            echo '<span style="color: #999;">—</span>';
        }
    }
}

// Initialize plugin
function courier_intelligence_init() {
    return Courier_Intelligence::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'courier_intelligence_init');

// Add JavaScript for copy button functionality in orders list
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen && ($screen->id === 'edit-shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.copy-voucher-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var voucher = $btn.data('voucher');
                
                // Create temporary textarea to copy
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(voucher).select();
                document.execCommand('copy');
                $temp.remove();
                
                // Visual feedback
                var originalHtml = $btn.html();
                $btn.html('<span class="dashicons dashicons-yes" style="font-size: 12px; width: 12px; height: 12px; color: #46b450;"></span>');
                $btn.prop('disabled', true);
                
                setTimeout(function() {
                    $btn.html(originalHtml);
                    $btn.prop('disabled', false);
                }, 1500);
            });
        });
        </script>
        <?php
    }
});

