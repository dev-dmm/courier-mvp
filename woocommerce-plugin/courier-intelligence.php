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
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-elta-api-client.php';
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-acs-api-client.php';
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-geniki-api-client.php';
require_once COURIER_INTELLIGENCE_PLUGIN_DIR . 'includes/class-speedex-api-client.php';
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
        
        // Add bulk actions for syncing orders
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_actions'), 10, 3);
        
        // Support for HPOS bulk actions
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_bulk_actions_hpos'), 10, 3);
        
        // AJAX handlers for syncing orders
        add_action('wp_ajax_courier_intelligence_sync_order', array($this, 'ajax_sync_order'));
        add_action('wp_ajax_courier_intelligence_sync_all_orders', array($this, 'ajax_sync_all_orders'));
        
        // Add admin notices for bulk action results
        add_action('admin_notices', array($this, 'show_bulk_action_notices'));
        
        // Add Elta tracking order actions
        add_filter('woocommerce_order_actions', array($this, 'add_elta_tracking_order_action'), 10, 1);
        add_action('woocommerce_order_action_check_elta_status', array($this, 'handle_check_elta_status'));
        
        // Add ACS tracking order actions
        add_filter('woocommerce_order_actions', array($this, 'add_acs_tracking_order_action'), 10, 1);
        add_action('woocommerce_order_action_check_acs_status', array($this, 'handle_check_acs_status'));
        
        // Schedule periodic voucher status updates
        $this->schedule_voucher_status_updates();
        add_action('courier_intelligence_check_voucher_statuses', array($this, 'check_all_voucher_statuses'));
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
        
        // After sending order, check if there are any vouchers/tracking numbers
        // that should be sent to the dashboard
        $this->check_and_send_existing_vouchers($order);
    }
    
    /**
     * Force sync order data to API (bypasses risk score check)
     * Used for manual sync operations
     */
    public function force_sync_order_data($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return array(
                'success' => false,
                'message' => 'Order not found',
            );
        }
        
        $settings = get_option('courier_intelligence_settings');
        
        if (empty($settings['api_endpoint']) || empty($settings['api_key']) || empty($settings['api_secret'])) {
            return array(
                'success' => false,
                'message' => 'API settings not configured',
            );
        }
        
        $order_data = $this->prepare_order_data($order);
        $result = $this->api_client->send_order($order_data, $order_id);
        
        // After sending order, check if there are any vouchers/tracking numbers
        $this->check_and_send_existing_vouchers($order);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
            );
        }
        
        return array(
            'success' => true,
            'message' => 'Order synced successfully',
        );
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
     * Check for existing vouchers when order is synced
     * This ensures vouchers are sent even if the meta update hook didn't fire
     * Also handles deletion of vouchers that were removed from WooCommerce
     */
    private function check_and_send_existing_vouchers($order) {
        // Get vouchers from any configured courier meta key
        $voucher_data = $this->get_vouchers_from_order($order);
        $current_vouchers = $voucher_data['vouchers'];
        
        // Get previously sent vouchers from order meta
        $previously_sent = (array) $order->get_meta('_oreksi_vouchers_sent', true);
        $previously_sent = array_map('trim', array_filter($previously_sent, 'is_string'));
        
        // Normalize current vouchers (trim and filter)
        $current_vouchers_normalized = array_map('trim', array_filter($current_vouchers, function($v) {
            return !empty($v) && is_string($v) && trim($v) !== '' && trim($v) !== '—';
        }));
        
        // Find vouchers that were sent but no longer exist (deleted)
        $deleted_vouchers = array_diff($previously_sent, $current_vouchers_normalized);
        
        // Delete vouchers that no longer exist
        if (!empty($deleted_vouchers)) {
            $settings = get_option('courier_intelligence_settings');
            $api_key = $settings['api_key'] ?? '';
            $api_secret = $settings['api_secret'] ?? '';
            
            // Get courier-specific API credentials if available
            $courier_key = $this->get_courier_key_from_name($voucher_data['courier_name']);
            if ($courier_key && !empty($settings['couriers'][$courier_key])) {
                $courier_settings = $settings['couriers'][$courier_key];
                if (!empty($courier_settings['api_key'])) {
                    $api_key = $courier_settings['api_key'];
                }
                if (!empty($courier_settings['api_secret'])) {
                    $api_secret = $courier_settings['api_secret'];
                }
            }
            
            foreach ($deleted_vouchers as $deleted_voucher) {
                if (!empty($deleted_voucher)) {
                    Courier_Intelligence_Logger::log('voucher', 'debug', array(
                        'external_order_id' => (string) $order->get_id(),
                        'message' => 'Voucher deleted from WooCommerce, sending deletion to API',
                        'voucher_number' => $deleted_voucher,
                    ));
                    
                    $this->api_client->delete_voucher($deleted_voucher, (string) $order->get_id(), $api_key, $api_secret);
                }
            }
            
            // Update the sent vouchers list to remove deleted ones
            $remaining_sent = array_intersect($previously_sent, $current_vouchers_normalized);
            $order->update_meta_data('_oreksi_vouchers_sent', array_values(array_unique($remaining_sent)));
            $order->save();
        }
        
        // Send current vouchers
        if (!empty($current_vouchers_normalized)) {
            // Log that we found vouchers when syncing order
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'Found existing vouchers when syncing order',
                'meta_key' => $voucher_data['meta_key'],
                'courier_name' => $voucher_data['courier_name'],
                'voucher_count' => count($current_vouchers_normalized),
                'vouchers' => $current_vouchers_normalized,
            ));
            
            // Send each unique voucher
            foreach ($current_vouchers_normalized as $tracking_number) {
                if (!empty($tracking_number)) {
                    $this->send_voucher_data($order, $tracking_number, $voucher_data['courier_name']);
                }
            }
        } elseif (!empty($previously_sent)) {
            // No current vouchers but had vouchers before - all were deleted
            // This case is already handled above, but update meta to clear the list
            $order->update_meta_data('_oreksi_vouchers_sent', array());
            $order->save();
        }
    }
    
    /**
     * Get all configured courier meta keys from settings
     * Returns array of meta keys with their courier names
     */
    private function get_configured_courier_meta_keys() {
        $settings = get_option('courier_intelligence_settings', array());
        $meta_keys = array();
        
        // Get courier-specific meta keys
        if (isset($settings['couriers']) && is_array($settings['couriers'])) {
            $courier_names = array(
                'acs' => 'ACS',
                'elta' => 'Elta',
                'speedex' => 'Speedex',
                'boxnow' => 'Boxnow',
                'geniki_taxidromiki' => 'Geniki Taxidromiki',
            );
            
            foreach ($settings['couriers'] as $courier_key => $courier_data) {
                if (!empty($courier_data['voucher_meta_key'])) {
                    $courier_name = $courier_names[$courier_key] ?? ucfirst(str_replace('_', ' ', $courier_key));
                    $meta_keys[$courier_data['voucher_meta_key']] = $courier_name;
                }
            }
        }
        
        // Legacy support: add old voucher_meta_key if set
        if (!empty($settings['voucher_meta_key'])) {
            $legacy_courier = !empty($settings['courier_name']) ? $settings['courier_name'] : 'Courier';
            $meta_keys[$settings['voucher_meta_key']] = $legacy_courier;
        }
        
        return $meta_keys;
    }
    
    /**
     * Get vouchers from order by checking all configured courier meta keys
     * Returns array with 'vouchers' (array of tracking numbers) and 'courier_name' (the courier that has the value)
     */
    private function get_vouchers_from_order($order) {
        $configured_meta_keys = $this->get_configured_courier_meta_keys();
        
        // If no meta keys configured, use default
        if (empty($configured_meta_keys)) {
            $vouchers = $this->get_all_vouchers_from_order($order, '_tracking_number');
            return array(
                'vouchers' => $vouchers,
                'courier_name' => null,
                'meta_key' => '_tracking_number',
            );
        }
        
        // Check each configured meta key in order
        foreach ($configured_meta_keys as $meta_key => $courier_name) {
            $vouchers = $this->get_all_vouchers_from_order($order, $meta_key);
            if (!empty($vouchers)) {
                return array(
                    'vouchers' => $vouchers,
                    'courier_name' => $courier_name,
                    'meta_key' => $meta_key,
                );
            }
        }
        
        // No vouchers found in any configured meta key
        return array(
            'vouchers' => array(),
            'courier_name' => null,
            'meta_key' => null,
        );
    }
    
    /**
     * Get all vouchers from an order's meta data for a specific meta key
     * Handles cases where vouchers might be stored as:
     * - Single value
     * - Multiple meta entries with the same key
     * - Array/serialized data
     */
    private function get_all_vouchers_from_order($order, $meta_key) {
        $vouchers = array();
        
        // Method 1: Try get_meta() which might return a single value or array
        $meta_value = $order->get_meta($meta_key);
        
        if (!empty($meta_value)) {
            if (is_array($meta_value)) {
                // If it's an array, add all non-empty string values
                foreach ($meta_value as $value) {
                    if (!empty($value) && is_string($value) && trim($value) !== '') {
                        $vouchers[] = trim($value);
                    }
                }
            } elseif (is_string($meta_value) && trim($meta_value) !== '') {
                // Single string value
                $vouchers[] = trim($meta_value);
            }
        }
        
        // Method 2: Check all meta data entries (in case there are multiple entries with same key)
        // This handles cases where WooCommerce stores multiple meta entries with the same key
        $all_meta = $order->get_meta_data();
        foreach ($all_meta as $meta) {
            if ($meta->key === $meta_key) {
                $value = $meta->value;
                if (!empty($value) && is_string($value) && trim($value) !== '') {
                    $trimmed = trim($value);
                    // Only add if not already in the array (avoid duplicates)
                    if (!in_array($trimmed, $vouchers, true)) {
                        $vouchers[] = $trimmed;
                    }
                }
            }
        }
        
        // Remove duplicates and empty values
        $vouchers = array_unique(array_filter($vouchers, function($v) {
            return !empty($v) && is_string($v) && trim($v) !== '' && trim($v) !== '—';
        }));
        
        return array_values($vouchers); // Re-index array
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
        
        // Throttle: Avoid spamming if hook fires multiple times within 30 seconds
        $last_check = (int) $order->get_meta('_oreksi_last_voucher_check');
        $now = time();
        
        if ($last_check && ($now - $last_check) < 30) {
            // Avoid spamming within 30 seconds
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'Throttled: check_for_tracking_update called too soon',
                'last_check' => $last_check,
                'now' => $now,
                'seconds_since' => $now - $last_check,
            ));
            return;
        }
        
        // Update last check timestamp
        $order->update_meta_data('_oreksi_last_voucher_check', $now);
        $order->save();
        
        // Debug: Log that hook was triggered
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'external_order_id' => (string) $order->get_id(),
            'message' => 'check_for_tracking_update triggered',
        ));
        
        // Get vouchers from any configured courier meta key
        $voucher_data = $this->get_vouchers_from_order($order);
        $vouchers = $voucher_data['vouchers'];
        
        // Debug: Log what we found
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'external_order_id' => (string) $order->get_id(),
            'message' => 'Checking tracking meta',
            'meta_key' => $voucher_data['meta_key'],
            'courier_name' => $voucher_data['courier_name'],
            'voucher_count' => count($vouchers),
            'vouchers' => $vouchers,
        ));
        
        if (!empty($vouchers)) {
            // Send each unique voucher
            foreach ($vouchers as $tracking_number) {
                if (!empty($tracking_number) && is_string($tracking_number)) {
                    $this->send_voucher_data($order, trim($tracking_number), $voucher_data['courier_name']);
                }
            }
        } else {
            // Debug: Log when tracking number is not found
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'Tracking number not found - voucher not sent',
                'error_code' => 'tracking_not_found',
            ));
        }
    }
    
    /**
     * Send voucher/tracking data to API
     */
    public function send_voucher_data($order, $tracking_number, $courier_name = null) {
        // Validate tracking number before sending
        $tracking_number = trim($tracking_number);
        
        // Skip if empty, just whitespace, or invalid
        if (empty($tracking_number) || 
            $tracking_number === '—' || 
            $tracking_number === '-' ||
            strlen($tracking_number) < 3) { // Minimum reasonable length for a tracking number
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'Skipping invalid or empty voucher',
                'tracking_number' => $tracking_number,
            ));
            return;
        }
        
        // Check if we've already sent this voucher for this order
        $sent = (array) $order->get_meta('_oreksi_vouchers_sent', true);
        if (in_array($tracking_number, $sent, true)) {
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'Voucher already sent, skipping',
                'tracking_number' => $tracking_number,
            ));
            return;
        }
        
        $settings = get_option('courier_intelligence_settings');
        
        // Get courier-specific API credentials if available
        $api_key = $settings['api_key'] ?? '';
        $api_secret = $settings['api_secret'] ?? '';
        
        // Find courier key from courier name (normalize to handle case variations)
        $courier_key = $this->get_courier_key_from_name($courier_name);
        if ($courier_key && !empty($settings['couriers'][$courier_key])) {
            $courier_settings = $settings['couriers'][$courier_key];
            // Use courier-specific credentials if set, otherwise use global
            if (!empty($courier_settings['api_key'])) {
                $api_key = $courier_settings['api_key'];
            }
            if (!empty($courier_settings['api_secret'])) {
                $api_secret = $courier_settings['api_secret'];
            }
        }
        
        if (empty($settings['api_endpoint']) || empty($api_key) || empty($api_secret)) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'API settings not configured - voucher not sent',
                'error_code' => 'missing_settings',
                'error_message' => 'API settings not configured',
            ));
            return;
        }
        
        $voucher_data = $this->prepare_voucher_data($order, $tracking_number, $courier_name);
        $result = $this->api_client->send_voucher($voucher_data, $api_key, $api_secret);
        
        // Mark as sent only if API call was successful
        if ($result !== false && !is_wp_error($result)) {
            $sent[] = $tracking_number;
            $order->update_meta_data('_oreksi_vouchers_sent', array_values(array_unique($sent)));
            $order->save();
        }
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
            // Status not sent - risk score is calculated from vouchers only (returns, late deliveries)
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
    private function prepare_voucher_data($order, $tracking_number, $courier_name = null) {
        $settings = get_option('courier_intelligence_settings');
        
        // Use provided courier name, or fall back to settings, or order meta
        if (empty($courier_name)) {
            $courier_name = $settings['courier_name'] ?? null;
        }
        
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
        // Get the order ID
        $order_id = $order->get_id();
        $score = null;
        
        // Method 1: Try order object's get_meta method (works for both traditional and HPOS)
        $score = $order->get_meta('_oreksi_risk_score', true);
        
        // Method 2: If not found, try get_post_meta for traditional post-based orders
        if (('' === $score || null === $score || $score === false) && is_numeric($order_id) && $order_id > 0) {
            $score = get_post_meta($order_id, '_oreksi_risk_score', true);
        }
        
        // Method 3: If still not found, try getting from meta data array directly
        if (('' === $score || null === $score || $score === false)) {
            $meta_data = $order->get_meta_data();
            foreach ($meta_data as $meta) {
                if ($meta->key === '_oreksi_risk_score') {
                    $score = $meta->value;
                    break;
                }
            }
        }
        
        // Method 4: Last resort - direct database query for traditional orders
        if (('' === $score || null === $score || $score === false) && is_numeric($order_id) && $order_id > 0) {
            global $wpdb;
            $meta_value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $order_id,
                '_oreksi_risk_score'
            ));
            if ($meta_value !== null) {
                $score = $meta_value;
            }
        }
        
        // Debug: Log what we retrieved (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Oreksi Risk Debug - Order ID: ' . $order_id . ', Score retrieved: ' . var_export($score, true) . ', Type: ' . gettype($score));
        }
        
        // Check if score is empty, null, false, or not set (0 is a valid score and should be displayed)
        if ('' === $score || null === $score || $score === false) {
            echo '—';
            return;
        }
        
        // Convert to integer (handles both string and integer values)
        $score = intval($score);
        
        // Ensure score is within valid range (0-100)
        if ($score < 0 || $score > 100) {
            echo '—';
            return;
        }
        
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
     * Shows all vouchers for the order (not just the first one)
     * Shows the courier name from the meta key that has a value
     * 
     * @param \WC_Order $order
     * @return void
     */
    private function render_voucher_column($order) {
        // Get vouchers from any configured courier meta key
        $voucher_data = $this->get_vouchers_from_order($order);
        $vouchers = $voucher_data['vouchers'];
        $courier_name = $voucher_data['courier_name'];
        
        if (empty($vouchers)) {
            echo '<span style="color: #999;">—</span>';
            return;
        }
        
        $is_first = true;
        
        foreach ($vouchers as $tracking_number) {
            // Add line break between vouchers (except for the first one)
            if (!$is_first) {
                echo '<br>';
            }
            $is_first = false;
            
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
        }
        
        // Show courier name once at the end if found
        if ($courier_name) {
            echo '<br><small style="color: #666;">' . esc_html($courier_name) . '</small>';
        }
    }
    
    /**
     * Add bulk actions to orders list
     */
    public function add_bulk_actions($actions) {
        $actions['courier_intelligence_sync'] = __('Sync to Courier Intelligence', 'courier-intelligence');
        return $actions;
    }
    
    /**
     * Handle bulk actions for traditional post-based orders
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'courier_intelligence_sync') {
            return $redirect_to;
        }
        
        $synced = 0;
        $failed = 0;
        
        foreach ($post_ids as $post_id) {
            $result = $this->force_sync_order_data($post_id);
            if ($result['success']) {
                $synced++;
            } else {
                $failed++;
            }
        }
        
        $redirect_to = add_query_arg(array(
            'courier_intelligence_synced' => $synced,
            'courier_intelligence_failed' => $failed,
        ), $redirect_to);
        
        return $redirect_to;
    }
    
    /**
     * Handle bulk actions for HPOS orders
     */
    public function handle_bulk_actions_hpos($redirect_to, $action, $order_ids) {
        if ($action !== 'courier_intelligence_sync') {
            return $redirect_to;
        }
        
        $synced = 0;
        $failed = 0;
        
        foreach ($order_ids as $order_id) {
            $result = $this->force_sync_order_data($order_id);
            if ($result['success']) {
                $synced++;
            } else {
                $failed++;
            }
        }
        
        $redirect_to = add_query_arg(array(
            'courier_intelligence_synced' => $synced,
            'courier_intelligence_failed' => $failed,
        ), $redirect_to);
        
        return $redirect_to;
    }
    
    /**
     * AJAX handler to sync a single order
     */
    public function ajax_sync_order() {
        check_ajax_referer('courier_intelligence_sync', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID'));
            return;
        }
        
        $result = $this->force_sync_order_data($order_id);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'order_id' => $order_id,
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message'],
                'order_id' => $order_id,
            ));
        }
    }
    
    /**
     * AJAX handler to sync all orders
     */
    public function ajax_sync_all_orders() {
        check_ajax_referer('courier_intelligence_sync_all', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 100;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        
        // Get orders
        $orders = wc_get_orders(array(
            'limit' => $limit,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'ids',
        ));
        
        $synced = 0;
        $failed = 0;
        $results = array();
        
        foreach ($orders as $order_id) {
            $result = $this->force_sync_order_data($order_id);
            if ($result['success']) {
                $synced++;
            } else {
                $failed++;
            }
            $results[] = array(
                'order_id' => $order_id,
                'success' => $result['success'],
                'message' => $result['message'],
            );
        }
        
        wp_send_json_success(array(
            'synced' => $synced,
            'failed' => $failed,
            'total' => count($orders),
            'offset' => $offset,
            'has_more' => count($orders) === $limit,
            'results' => $results,
        ));
    }
    
    /**
     * Show admin notices for bulk action results
     */
    public function show_bulk_action_notices() {
        if (!isset($_GET['courier_intelligence_synced']) && !isset($_GET['courier_intelligence_failed'])) {
            return;
        }
        
        $synced = isset($_GET['courier_intelligence_synced']) ? absint($_GET['courier_intelligence_synced']) : 0;
        $failed = isset($_GET['courier_intelligence_failed']) ? absint($_GET['courier_intelligence_failed']) : 0;
        
        if ($synced > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                _n(
                    '%d order synced successfully.',
                    '%d orders synced successfully.',
                    $synced,
                    'courier-intelligence'
                ),
                $synced
            );
            echo '</p></div>';
        }
        
        if ($failed > 0) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            printf(
                _n(
                    '%d order failed to sync.',
                    '%d orders failed to sync.',
                    $failed,
                    'courier-intelligence'
                ),
                $failed
            );
            echo '</p></div>';
        }
    }
    
    /**
     * Add "Check Elta voucher status" to order actions dropdown
     * 
     * @param array $actions Existing order actions
     * @return array Modified actions
     */
    public function add_elta_tracking_order_action($actions) {
        // Only show if Elta is configured
        $settings = get_option('courier_intelligence_settings', array());
        $elta_settings = $settings['couriers']['elta'] ?? array();
        
        if (!empty($elta_settings['user_code']) && !empty($elta_settings['apost_code'])) {
            $actions['check_elta_status'] = __('Check Elta voucher status', 'courier-intelligence');
        }
        
        return $actions;
    }
    
    /**
     * Handle "Check Elta voucher status" order action
     * 
     * @param \WC_Order $order WooCommerce order object
     */
    public function handle_check_elta_status($order) {
        if (!$order instanceof WC_Order) {
            return;
        }
        
        $order_id = $order->get_id();
        
        // Get Elta voucher from configured meta key
        $settings = get_option('courier_intelligence_settings', array());
        $elta_settings = $settings['couriers']['elta'] ?? array();
        $voucher_meta_key = $elta_settings['voucher_meta_key'] ?? '';
        
        // If no specific meta key configured, try to find Elta voucher from all configured keys
        if (empty($voucher_meta_key)) {
            $voucher_data = $this->get_vouchers_from_order($order);
            // Only proceed if courier is Elta
            if ($voucher_data['courier_name'] !== 'Elta') {
                $order->add_order_note(__('Elta tracking: No Elta voucher found. Please configure Elta voucher meta key in settings.', 'courier-intelligence'));
                return;
            }
            $vouchers = $voucher_data['vouchers'];
            $voucher = !empty($vouchers) ? $vouchers[0] : '';
        } else {
            // Get voucher from specific meta key
            $voucher = $order->get_meta($voucher_meta_key);
            if (is_array($voucher)) {
                $voucher = !empty($voucher) ? $voucher[0] : '';
            }
            $voucher = trim($voucher);
        }
        
        if (empty($voucher)) {
            $order->add_order_note(__('Elta tracking: No voucher number found in order meta.', 'courier-intelligence'));
            return;
        }
        
        // Initialize Elta API client
        $client = new Courier_Intelligence_Elta_API_Client();
        
        // Get voucher status
        $result = $client->get_voucher_status($voucher);
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $order->add_order_note(sprintf(
                __('Elta tracking error: %s', 'courier-intelligence'),
                $error_message
            ));
            
            // Log the error
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => (string) $order_id,
                'message' => 'Failed to check Elta voucher status',
                'voucher_number' => $voucher,
                'error_code' => $result->get_error_code(),
                'error_message' => $error_message,
                'courier' => 'Elta',
            ));
            
            return;
        }
        
        // Save tracking data to order meta
        $order->update_meta_data('_elta_status', $result['status'] ?? 'unknown');
        $order->update_meta_data('_elta_status_title', $result['status_title'] ?? '');
        $order->update_meta_data('_elta_last_tracking', current_time('mysql'));
        $order->update_meta_data('_elta_tracking_events_count', $result['status_counter'] ?? 0);
        
        if (!empty($result['delivered']) && !empty($result['delivery_date'])) {
            $order->update_meta_data('_elta_delivered', 'yes');
            $order->update_meta_data('_elta_delivered_date', $result['delivery_date']);
            $order->update_meta_data('_elta_delivered_time', $result['delivery_time'] ?? '');
            if (!empty($result['recipient_name'])) {
                $order->update_meta_data('_elta_recipient_name', $result['recipient_name']);
            }
        } else {
            $order->update_meta_data('_elta_delivered', 'no');
        }
        
        // Store raw response for debugging (optional, can be removed if not needed)
        $order->update_meta_data('_elta_last_response', $result);
        
        $order->save();
        
        // Create human-readable order note
        $status_text = $result['status_title'] ?? $result['status'] ?? 'Unknown';
        $status_code = $result['status'] ?? 'unknown';
        
        $note = sprintf(
            __('Elta tracking: %s (%s)', 'courier-intelligence'),
            $status_text,
            $status_code
        );
        
        if (!empty($result['delivered']) && !empty($result['delivery_date'])) {
            $delivery_info = $result['delivery_date'];
            if (!empty($result['delivery_time'])) {
                $delivery_info .= ' ' . $result['delivery_time'];
            }
            $note .= ' - ' . sprintf(__('Delivered on %s', 'courier-intelligence'), $delivery_info);
            
            if (!empty($result['recipient_name'])) {
                $note .= ' (' . $result['recipient_name'] . ')';
            }
        } elseif (!empty($result['events']) && count($result['events']) > 0) {
            $note .= ' - ' . sprintf(
                _n('%d tracking event', '%d tracking events', count($result['events']), 'courier-intelligence'),
                count($result['events'])
            );
        }
        
        $order->add_order_note($note);
        
        // Log success
        Courier_Intelligence_Logger::log('voucher', 'success', array(
            'external_order_id' => (string) $order_id,
            'message' => 'Elta voucher status checked successfully',
            'voucher_number' => $voucher,
            'status' => $status_code,
            'delivered' => !empty($result['delivered']),
            'courier' => 'Elta',
        ));
    }
    
    /**
     * Add "Check ACS voucher status" order action
     * 
     * @param array $actions Existing order actions
     * @return array Modified actions
     */
    public function add_acs_tracking_order_action($actions) {
        // Only show if ACS is configured
        $settings = get_option('courier_intelligence_settings', array());
        $acs_settings = $settings['couriers']['acs'] ?? array();
        
        if (!empty($acs_settings['api_key']) && !empty($acs_settings['company_id'])) {
            $actions['check_acs_status'] = __('Check ACS voucher status', 'courier-intelligence');
        }
        
        return $actions;
    }
    
    /**
     * Handle "Check ACS voucher status" order action
     * 
     * @param \WC_Order $order WooCommerce order object
     */
    public function handle_check_acs_status($order) {
        if (!$order instanceof WC_Order) {
            return;
        }
        
        $order_id = $order->get_id();
        
        // Get ACS voucher from configured meta key
        $settings = get_option('courier_intelligence_settings', array());
        $acs_settings = $settings['couriers']['acs'] ?? array();
        $voucher_meta_key = $acs_settings['voucher_meta_key'] ?? '';
        
        // If no specific meta key configured, try to find ACS voucher from all configured keys
        if (empty($voucher_meta_key)) {
            $voucher_data = $this->get_vouchers_from_order($order);
            // Only proceed if courier is ACS
            if ($voucher_data['courier_name'] !== 'ACS') {
                $order->add_order_note(__('ACS tracking: No ACS voucher found. Please configure ACS voucher meta key in settings.', 'courier-intelligence'));
                return;
            }
            $vouchers = $voucher_data['vouchers'];
            $voucher = !empty($vouchers) ? $vouchers[0] : '';
        } else {
            // Get voucher from specific meta key
            $voucher = $order->get_meta($voucher_meta_key);
            if (is_array($voucher)) {
                $voucher = !empty($voucher) ? $voucher[0] : '';
            }
            $voucher = trim($voucher);
        }
        
        if (empty($voucher)) {
            $order->add_order_note(__('ACS tracking: No voucher number found in order meta.', 'courier-intelligence'));
            return;
        }
        
        // Initialize ACS API client
        $client = new Courier_Intelligence_ACS_API_Client();
        
        // Get voucher status
        $result = $client->get_voucher_status($voucher);
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $order->add_order_note(sprintf(
                __('ACS tracking error: %s', 'courier-intelligence'),
                $error_message
            ));
            
            // Log the error
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => (string) $order_id,
                'message' => 'Failed to check ACS voucher status',
                'voucher_number' => $voucher,
                'error_code' => $result->get_error_code(),
                'error_message' => $error_message,
                'courier' => 'ACS',
            ));
            
            return;
        }
        
        // Save tracking data to order meta
        $order->update_meta_data('_acs_status', $result['status'] ?? 'unknown');
        $order->update_meta_data('_acs_status_title', $result['status_title'] ?? '');
        $order->update_meta_data('_acs_last_tracking', current_time('mysql'));
        $order->update_meta_data('_acs_tracking_events_count', $result['status_counter'] ?? 0);
        
        if (!empty($result['delivered']) && !empty($result['delivery_date'])) {
            $order->update_meta_data('_acs_delivered', 'yes');
            $order->update_meta_data('_acs_delivered_date', $result['delivery_date']);
            $order->update_meta_data('_acs_delivered_time', $result['delivery_time'] ?? '');
            if (!empty($result['recipient_name'])) {
                $order->update_meta_data('_acs_recipient_name', $result['recipient_name']);
            }
        } else {
            $order->update_meta_data('_acs_delivered', 'no');
        }
        
        // Store raw response for debugging (optional, can be removed if not needed)
        $order->update_meta_data('_acs_last_response', $result);
        
        $order->save();
        
        // Create human-readable order note
        $status_text = $result['status_title'] ?? $result['status'] ?? 'Unknown';
        $status_code = $result['status'] ?? 'unknown';
        
        $note = sprintf(
            __('ACS tracking: %s (%s)', 'courier-intelligence'),
            $status_text,
            $status_code
        );
        
        if (!empty($result['delivered']) && !empty($result['delivery_date'])) {
            $delivery_info = $result['delivery_date'];
            if (!empty($result['delivery_time'])) {
                $delivery_info .= ' ' . $result['delivery_time'];
            }
            $note .= ' - ' . sprintf(__('Delivered on %s', 'courier-intelligence'), $delivery_info);
            
            if (!empty($result['recipient_name'])) {
                $note .= ' (' . $result['recipient_name'] . ')';
            }
        } elseif (!empty($result['events']) && count($result['events']) > 0) {
            $note .= ' - ' . sprintf(
                _n('%d tracking event', '%d tracking events', count($result['events']), 'courier-intelligence'),
                count($result['events'])
            );
        }
        
        $order->add_order_note($note);
        
        // Log success
        Courier_Intelligence_Logger::log('voucher', 'success', array(
            'external_order_id' => (string) $order_id,
            'message' => 'ACS voucher status checked successfully',
            'voucher_number' => $voucher,
            'status' => $status_code,
            'delivered' => !empty($result['delivered']),
            'courier' => 'ACS',
        ));
    }
    
    /**
     * Schedule periodic voucher status updates
     * 
     * Public method so it can be called from settings class when settings are updated
     */
    public function schedule_voucher_status_updates() {
        $settings = get_option('courier_intelligence_settings', array());
        $enable_periodic_updates = $settings['enable_periodic_voucher_updates'] ?? 'yes';
        
        // Clear any existing scheduled event first
        $timestamp = wp_next_scheduled('courier_intelligence_check_voucher_statuses');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'courier_intelligence_check_voucher_statuses');
        }
        
        if ($enable_periodic_updates !== 'yes') {
            return;
        }
        
        // Schedule with the configured interval
        $interval = $settings['voucher_update_interval'] ?? 'hourly'; // hourly, twicedaily, daily
        wp_schedule_event(time(), $interval, 'courier_intelligence_check_voucher_statuses');
    }
    
    /**
     * Manually trigger voucher status check (for testing)
     * Same as check_all_voucher_statuses() but with more detailed error logging
     * 
     * @param int $limit Optional limit on number of orders to process (default: 50)
     * @return array Results with checked, updated, errors counts
     */
    public function manual_check_voucher_statuses($limit = 50) {
        $settings = get_option('courier_intelligence_settings', array());
        $enable_periodic_updates = $settings['enable_periodic_voucher_updates'] ?? 'yes';
        
        Courier_Intelligence_Logger::log('voucher', 'info', array(
            'message' => 'Manual voucher status check triggered',
            'limit' => $limit,
        ));
        
        if ($enable_periodic_updates !== 'yes') {
            $error_msg = 'Periodic voucher updates are disabled in settings';
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => $error_msg,
            ));
            return array(
                'success' => false,
                'error' => $error_msg,
                'checked' => 0,
                'updated' => 0,
                'errors' => 0,
            );
        }
        
        // Check API settings
        if (empty($settings['api_endpoint']) || empty($settings['api_key']) || empty($settings['api_secret'])) {
            $error_msg = 'API settings not configured';
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'message' => $error_msg,
                'has_endpoint' => !empty($settings['api_endpoint']),
                'has_api_key' => !empty($settings['api_key']),
                'has_api_secret' => !empty($settings['api_secret']),
            ));
            return array(
                'success' => false,
                'error' => $error_msg,
                'checked' => 0,
                'updated' => 0,
                'errors' => 0,
            );
        }
        
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'message' => 'Starting manual voucher status check',
            'limit' => $limit,
        ));
        
        // Get all orders with vouchers
        $orders = $this->get_orders_with_vouchers();
        
        if (empty($orders)) {
            // Log detailed debug info to help diagnose why no orders were found
            $configured_meta_keys = $this->get_configured_courier_meta_keys();
            $test_args = array(
                'limit' => 10,
                'status' => 'any',
                'date_created' => '>=' . (time() - (90 * DAY_IN_SECONDS)),
            );
            $test_orders = wc_get_orders($test_args);
            
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'message' => 'No orders with vouchers found for status check',
                'configured_meta_keys' => $configured_meta_keys,
                'total_orders_in_range' => count($test_orders),
                'date_range_days' => 90,
            ));
            
            return array(
                'success' => true,
                'checked' => 0,
                'updated' => 0,
                'errors' => 0,
                'message' => 'No orders with vouchers found. Check Activity Logs for details.',
                'debug_info' => array(
                    'configured_meta_keys' => array_keys($configured_meta_keys),
                    'total_orders_in_range' => count($test_orders),
                ),
            );
        }
        
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'message' => 'Found orders with vouchers',
            'total_orders' => count($orders),
            'will_process' => min(count($orders), $limit),
        ));
        
        $checked = 0;
        $updated = 0;
        $errors = 0;
        $error_details = array();
        
        foreach ($orders as $order) {
            $order_id = $order->get_id();
            
            try {
                Courier_Intelligence_Logger::log('voucher', 'debug', array(
                    'message' => 'Checking voucher status for order',
                    'external_order_id' => (string) $order_id,
                ));
                
                $result = $this->check_and_update_voucher_status($order);
                
                if (is_wp_error($result)) {
                    $errors++;
                    $error_code = $result->get_error_code();
                    $error_message = $result->get_error_message();
                    $error_details[] = array(
                        'order_id' => $order_id,
                        'error_code' => $error_code,
                        'error_message' => $error_message,
                    );
                    
                    Courier_Intelligence_Logger::log('voucher', 'error', array(
                        'external_order_id' => (string) $order_id,
                        'message' => 'Failed to check/update voucher status',
                        'error_code' => $error_code,
                        'error_message' => $error_message,
                    ));
                } elseif ($result === true) {
                    $updated++;
                    Courier_Intelligence_Logger::log('voucher', 'debug', array(
                        'external_order_id' => (string) $order_id,
                        'message' => 'Voucher status updated successfully',
                    ));
                } else {
                    Courier_Intelligence_Logger::log('voucher', 'debug', array(
                        'external_order_id' => (string) $order_id,
                        'message' => 'Voucher status unchanged (no update needed)',
                    ));
                }
            } catch (Exception $e) {
                $errors++;
                $error_details[] = array(
                    'order_id' => $order_id,
                    'error_code' => 'exception',
                    'error_message' => $e->getMessage(),
                );
                
                Courier_Intelligence_Logger::log('voucher', 'error', array(
                    'external_order_id' => (string) $order_id,
                    'message' => 'Exception during voucher status check',
                    'error_code' => 'exception',
                    'error_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ));
            }
            
            $checked++;
            
            // Limit processing to avoid timeouts
            if ($checked >= $limit) {
                Courier_Intelligence_Logger::log('voucher', 'debug', array(
                    'message' => 'Reached processing limit',
                    'limit' => $limit,
                ));
                break;
            }
        }
        
        $summary = array(
            'success' => true,
            'checked' => $checked,
            'updated' => $updated,
            'errors' => $errors,
            'total_orders' => count($orders),
            'error_details' => $error_details,
        );
        
        Courier_Intelligence_Logger::log('voucher', 'info', array(
            'message' => 'Manual voucher status check completed',
            'checked' => $checked,
            'updated' => $updated,
            'errors' => $errors,
            'total_orders' => count($orders),
        ));
        
        return $summary;
    }
    
    /**
     * Check all voucher statuses and send updates
     * Called by WordPress cron
     */
    public function check_all_voucher_statuses() {
        $settings = get_option('courier_intelligence_settings', array());
        $enable_periodic_updates = $settings['enable_periodic_voucher_updates'] ?? 'yes';
        
        if ($enable_periodic_updates !== 'yes') {
            return;
        }
        
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'message' => 'Starting periodic voucher status check',
        ));
        
        // Get all orders with vouchers
        $orders = $this->get_orders_with_vouchers();
        
        if (empty($orders)) {
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'message' => 'No orders with vouchers found for status check',
            ));
            return;
        }
        
        $checked = 0;
        $updated = 0;
        $errors = 0;
        
        foreach ($orders as $order) {
            $result = $this->check_and_update_voucher_status($order);
            
            if (is_wp_error($result)) {
                $errors++;
            } elseif ($result === true) {
                $updated++;
            }
            
            $checked++;
            
            // Limit processing per cron run to avoid timeouts
            // Process max 50 orders per run
            if ($checked >= 50) {
                break;
            }
        }
        
        Courier_Intelligence_Logger::log('voucher', 'info', array(
            'message' => 'Periodic voucher status check completed',
            'checked' => $checked,
            'updated' => $updated,
            'errors' => $errors,
        ));
    }
    
    /**
     * Get all orders that have vouchers
     * 
     * @return array Array of WC_Order objects
     */
    private function get_orders_with_vouchers() {
        $orders = array();
        $configured_meta_keys = $this->get_configured_courier_meta_keys();
        
        // If no meta keys configured, use default
        if (empty($configured_meta_keys)) {
            $configured_meta_keys = array('_tracking_number' => null);
        }
        
        // Get orders from last 90 days that have vouchers
        // Include all statuses except trash/refunded to catch cancelled orders with vouchers too
        $args = array(
            'limit' => -1,
            'status' => array('wc-processing', 'wc-completed', 'wc-shipped', 'wc-cancelled', 'wc-pending', 'wc-on-hold', 'wc-failed'),
            'date_created' => '>=' . (time() - (90 * DAY_IN_SECONDS)),
            'meta_query' => array(
                'relation' => 'OR',
            ),
        );
        
        // Add meta query for each configured meta key
        foreach ($configured_meta_keys as $meta_key => $courier_name) {
            $args['meta_query'][] = array(
                'key' => $meta_key,
                'value' => '',
                'compare' => '!=',
            );
        }
        
        $wc_orders = wc_get_orders($args);
        
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'message' => 'Querying orders with vouchers',
            'total_orders_found' => count($wc_orders),
            'status_filter' => $args['status'],
            'date_range_days' => 90,
            'configured_meta_keys' => array_keys($configured_meta_keys),
        ));
        
        // Filter to only include orders that actually have valid vouchers
        foreach ($wc_orders as $order) {
            $voucher_data = $this->get_vouchers_from_order($order);
            if (!empty($voucher_data['vouchers'])) {
                $orders[] = $order;
                Courier_Intelligence_Logger::log('voucher', 'debug', array(
                    'message' => 'Found order with voucher',
                    'external_order_id' => (string) $order->get_id(),
                    'order_status' => $order->get_status(),
                    'voucher_count' => count($voucher_data['vouchers']),
                    'courier_name' => $voucher_data['courier_name'],
                    'meta_key' => $voucher_data['meta_key'],
                ));
            }
        }
        
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'message' => 'Finished filtering orders with vouchers',
            'total_orders_with_vouchers' => count($orders),
        ));
        
        return $orders;
    }
    
    /**
     * Check and update voucher status for a single order
     * 
     * @param WC_Order $order
     * @return bool|WP_Error True if updated, false if no change, WP_Error on error
     */
    private function check_and_update_voucher_status($order) {
        $voucher_data = $this->get_vouchers_from_order($order);
        
        if (empty($voucher_data['vouchers']) || empty($voucher_data['courier_name'])) {
            return false;
        }
        
        $courier_name = $voucher_data['courier_name'];
        $vouchers = $voucher_data['vouchers'];
        
        // Get API client for this courier
        $api_client = $this->get_courier_api_client($courier_name);
        
        if (is_wp_error($api_client)) {
            return $api_client;
        }
        
        // Check status for the first voucher (primary tracking number)
        $voucher = $vouchers[0];
        $status_result = $api_client->get_voucher_status($voucher);
        
        if (is_wp_error($status_result)) {
            Courier_Intelligence_Logger::log('voucher', 'error', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'Failed to get voucher status for periodic update',
                'voucher_number' => $voucher,
                'courier_name' => $courier_name,
                'error_code' => $status_result->get_error_code(),
                'error_message' => $status_result->get_error_message(),
            ));
            return $status_result;
        }
        
        // Log the raw courier response and events for debugging
        Courier_Intelligence_Logger::log('voucher', 'debug', array(
            'external_order_id' => (string) $order->get_id(),
            'message' => 'Received voucher status from courier API',
            'voucher_number' => $voucher,
            'courier_name' => $courier_name,
            'status' => $status_result['status'] ?? 'unknown',
            'status_title' => $status_result['status_title'] ?? '',
            'events_count' => count($status_result['events'] ?? array()),
            'events' => $status_result['events'] ?? array(), // Full event history
            'raw_response' => $status_result['raw_response'] ?? null, // Raw courier API response
            'delivered' => !empty($status_result['delivered']),
            'delivery_date' => $status_result['delivery_date'] ?? null,
        ));
        
        // Get last known status from order meta
        $last_status = $order->get_meta('_oreksi_last_voucher_status');
        $current_status = $status_result['status'] ?? 'unknown';
        $current_status_title = $status_result['status_title'] ?? '';
        
        // Check if status has changed
        $status_changed = false;
        if (empty($last_status) || $last_status !== $current_status) {
            $status_changed = true;
        }
        
        // Always send update if it's been more than 24 hours since last update
        $last_update = (int) $order->get_meta('_oreksi_last_voucher_status_update');
        $force_update = empty($last_update) || (time() - $last_update) > (24 * HOUR_IN_SECONDS);
        
        if ($status_changed || $force_update) {
            // Send status update to server
            $update_result = $this->send_voucher_status_update($order, $voucher, $courier_name, $status_result);
            
            if (!is_wp_error($update_result)) {
                // Update order meta with current status
                $order->update_meta_data('_oreksi_last_voucher_status', $current_status);
                $order->update_meta_data('_oreksi_last_voucher_status_title', $current_status_title);
                $order->update_meta_data('_oreksi_last_voucher_status_update', time());
                $order->save();
                
                return true;
            }
            
            return $update_result;
        }
        
        return false; // No update needed
    }
    
    /**
     * Get courier settings key from courier name
     * Normalizes courier name to handle case variations
     * 
     * @param string $courier_name Courier name (e.g., "Elta", "ELTA", "elta")
     * @return string|null Courier settings key (e.g., "elta") or null if not found
     */
    private function get_courier_key_from_name($courier_name) {
        if (empty($courier_name)) {
            return null;
        }
        
        $courier_key_map = array(
            'ACS' => 'acs',
            'ELTA' => 'elta',
            'SPEEDEX' => 'speedex',
            'BOXNOW' => 'boxnow',
            'GENIKI TAXIDROMIKI' => 'geniki_taxidromiki',
            'GENIKI' => 'geniki_taxidromiki', // Also support just "Geniki"
        );
        
        $normalized = strtoupper($courier_name);
        return $courier_key_map[$normalized] ?? null;
    }
    
    /**
     * Get appropriate API client for courier
     * 
     * @param string $courier_name
     * @return object|WP_Error API client instance or error
     */
    private function get_courier_api_client($courier_name) {
        switch (strtoupper($courier_name)) {
            case 'ELTA':
                return new Courier_Intelligence_Elta_API_Client();
            
            case 'ACS':
                return new Courier_Intelligence_ACS_API_Client();
            
            case 'GENIKI':
            case 'GENIKI TAXIDROMIKI':
                return new Courier_Intelligence_Geniki_API_Client();
            
            case 'SPEEDEX':
                return new Courier_Intelligence_Speedex_API_Client();
            
            default:
                return new WP_Error('unsupported_courier', sprintf('Courier "%s" is not supported for status updates', $courier_name));
        }
    }
    
    /**
     * Prepare and send voucher status update to API
     * 
     * @param WC_Order $order
     * @param string $voucher_number
     * @param string $courier_name
     * @param array $status_data Status data from courier API
     * @return bool|WP_Error
     */
    private function send_voucher_status_update($order, $voucher_number, $courier_name, $status_data) {
        $settings = get_option('courier_intelligence_settings');
        
        if (empty($settings['api_endpoint']) || empty($settings['api_key']) || empty($settings['api_secret'])) {
            return new WP_Error('missing_settings', 'API settings not configured');
        }
        
        // Prepare status update data
        // Map courier status - ensure we have a valid status
        $raw_status = strtolower($status_data['status'] ?? 'created');
        // Map 'issue' (from Elta) to 'created' - Elta uses "issue" when not yet scanned in processing center
        // It does NOT mean failure, it's an internal placeholder that should be treated as created
        $status_map = array(
            'issue' => 'created', // Elta "issue" = not yet scanned, not a failure
            'unknown' => 'created',
            'initial' => 'created',
            '' => 'created', // Empty string also defaults to created
        );
        $raw_status = $status_map[$raw_status] ?? $raw_status;
        
        // Default to 'created' if status is empty or unknown (fallback)
        if (empty($raw_status) || $raw_status === 'unknown') {
            $raw_status = 'created';
        }
        
        $status_update = array(
            'voucher_number' => $voucher_number,
            'external_order_id' => (string) $order->get_id(),
            'courier_name' => $courier_name,
            'status' => $raw_status,
            'status_title' => $status_data['status_title'] ?? '',
            'delivered' => !empty($status_data['delivered']),
            'returned' => !empty($status_data['returned']),
            'delivery_date' => $status_data['delivery_date'] ?? null,
            'delivery_time' => $status_data['delivery_time'] ?? null,
            'recipient_name' => $status_data['recipient_name'] ?? null,
            'events_count' => count($status_data['events'] ?? array()),
            'events' => $status_data['events'] ?? array(), // Include full event history
            'updated_at' => current_time('mysql'),
        );
        
        // Log the events being sent for debugging
        if (!empty($status_data['events'])) {
            Courier_Intelligence_Logger::log('voucher', 'debug', array(
                'external_order_id' => (string) $order->get_id(),
                'message' => 'Preparing to send voucher status update with events',
                'voucher_number' => $voucher_number,
                'courier_name' => $courier_name,
                'events_count' => count($status_data['events']),
                'events' => $status_data['events'], // Full event history
            ));
        }
        
        // Hash customer email if available
        $customer_email = $order->get_billing_email();
        if (!empty($customer_email)) {
            $status_update['customer_hash'] = Courier_Intelligence_Customer_Hasher::hash_email($customer_email);
        }
        
        // Get courier-specific API credentials if available
        $api_key = $settings['api_key'] ?? '';
        $api_secret = $settings['api_secret'] ?? '';
        
        // Find courier key from courier name (normalize to handle case variations)
        $courier_key = $this->get_courier_key_from_name($courier_name);
        if ($courier_key && !empty($settings['couriers'][$courier_key])) {
            $courier_settings = $settings['couriers'][$courier_key];
            if (!empty($courier_settings['api_key'])) {
                $api_key = $courier_settings['api_key'];
            }
            if (!empty($courier_settings['api_secret'])) {
                $api_secret = $courier_settings['api_secret'];
            }
        }
        
        // Send to API
        $result = $this->api_client->send_voucher_status_update($status_update, $api_key, $api_secret);
        
        return $result;
    }
}

// Initialize plugin
function courier_intelligence_init() {
    return Courier_Intelligence::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'courier_intelligence_init');

// Activation hook - schedule cron job
register_activation_hook(__FILE__, function() {
    $plugin = Courier_Intelligence::get_instance();
    if (method_exists($plugin, 'schedule_voucher_status_updates')) {
        $plugin->schedule_voucher_status_updates();
    }
});

// Deactivation hook - unschedule cron job
register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('courier_intelligence_check_voucher_statuses');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'courier_intelligence_check_voucher_statuses');
    }
});

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

