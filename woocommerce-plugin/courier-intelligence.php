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
        add_action('woocommerce_order_meta_updated', array($this, 'check_for_tracking_update'), 10, 1);
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
        
        $order_data = $this->prepare_order_data($order);
        
        $this->api_client->send_order($order_data);
    }
    
    /**
     * Check for tracking number updates
     */
    public function check_for_tracking_update($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $settings = get_option('courier_intelligence_settings');
        $voucher_meta_key = $settings['voucher_meta_key'] ?? '_tracking_number';
        
        // If no meta key is configured, use default
        if (empty($voucher_meta_key)) {
            $voucher_meta_key = '_tracking_number';
        }
        
        // Check if tracking number exists in order meta using the configured meta key
        $tracking_number = $order->get_meta($voucher_meta_key);
        
        if ($tracking_number) {
            $this->send_voucher_data($order, $tracking_number);
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
     * Privacy Note: Customer email is sent over HTTPS with HMAC authentication.
     * The backend immediately hashes the email using SHA256(strtolower(trim(email)))
     * to create a customer_hash for cross-shop matching. The email is stored
     * only as primary_email (optional) and is not used for cross-shop analytics.
     * All cross-shop statistics are based on customer_hash only.
     */
    private function prepare_order_data($order) {
        $shipping_address = $order->get_address('shipping');
        
        return array(
            'external_order_id' => (string) $order->get_id(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
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
    }
    
    /**
     * Prepare voucher data for API
     */
    private function prepare_voucher_data($order, $tracking_number) {
        $settings = get_option('courier_intelligence_settings');
        $courier_name = $settings['courier_name'] ?? null;
        
        // Use configured courier name, or fall back to order meta
        if (empty($courier_name)) {
            $courier_name = $order->get_meta('_courier_name') ?: null;
        }
        
        return array(
            'voucher_number' => $tracking_number,
            'external_order_id' => (string) $order->get_id(),
            'customer_email' => $order->get_billing_email(),
            'courier_name' => $courier_name,
            'courier_service' => $order->get_meta('_courier_service') ?: null,
            'tracking_url' => $order->get_meta('_tracking_url') ?: null,
            'status' => $this->map_order_status_to_voucher_status($order->get_status()),
            'shipped_at' => $order->get_meta('_shipped_at') ?: null,
        );
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
}

// Initialize plugin
function courier_intelligence_init() {
    return Courier_Intelligence::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'courier_intelligence_init');

