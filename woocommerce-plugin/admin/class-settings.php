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
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Courier Intelligence',
            'Courier Intelligence',
            'manage_woocommerce',
            'courier-intelligence',
            array($this, 'render_settings_page')
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
        
        return $sanitized;
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $settings = get_option('courier_intelligence_settings', array());
        
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
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

