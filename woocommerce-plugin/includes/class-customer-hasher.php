<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customer Data Hasher for GDPR Compliance
 * 
 * This class provides pseudonymization of customer PII (Personally Identifiable Information)
 * before sending data to the API. All hashing uses a global salt that must match
 * the salt configured in the Laravel backend (CUSTOMER_HASH_SALT).
 * 
 * This ensures:
 * - Same customer produces same hash across all shops
 * - No raw PII is transmitted to the API
 * - Cross-shop customer matching without storing personal data
 */
class Courier_Intelligence_Customer_Hasher {
    
    /**
     * Get the global salt for hashing
     * 
     * This must match CUSTOMER_HASH_SALT in Laravel .env file.
     * Store it in WordPress options or use a constant.
     * 
     * @return string
     */
    private static function get_salt() {
        // Option 1: Get from plugin settings (recommended)
        $settings = get_option('courier_intelligence_settings', array());
        $salt = $settings['hash_salt'] ?? '';
        
        // Option 2: Use constant if defined in wp-config.php
        if (empty($salt) && defined('COURIER_INTELLIGENCE_HASH_SALT')) {
            $salt = COURIER_INTELLIGENCE_HASH_SALT;
        }
        
        // Option 3: Legacy option name (for backward compatibility)
        if (empty($salt)) {
            $salt = get_option('courier_intelligence_hash_salt', '');
        }
        
        // If still empty, throw error
        if (empty($salt)) {
            error_log('Courier Intelligence: CUSTOMER_HASH_SALT not configured. Please set it in plugin settings or wp-config.php');
            throw new Exception('CUSTOMER_HASH_SALT must be configured for GDPR compliance. Please set it in Courier Intelligence settings.');
        }
        
        return $salt;
    }
    
    /**
     * Generate hash for email address
     * 
     * @param string $email
     * @return string SHA256 hash
     */
    public static function hash_email($email) {
        if (empty($email)) {
            return null;
        }
        
        // Normalize: lowercase and trim
        $normalized = strtolower(trim($email));
        
        // Get salt and hash
        $salt = self::get_salt();
        return hash('sha256', $normalized . $salt);
    }
    
    /**
     * Generate hash for phone number
     * 
     * @param string $phone
     * @return string SHA256 hash
     */
    public static function hash_phone($phone) {
        if (empty($phone)) {
            return null;
        }
        
        // Normalize: remove spaces, dashes, parentheses
        $normalized = preg_replace('/[\s\-\(\)]/', '', trim($phone));
        
        if (empty($normalized)) {
            return null;
        }
        
        $salt = self::get_salt();
        return hash('sha256', $normalized . $salt);
    }
    
    /**
     * Generate hash for name
     * 
     * @param string $name
     * @return string SHA256 hash
     */
    public static function hash_name($name) {
        if (empty($name)) {
            return null;
        }
        
        // Normalize: lowercase, trim, remove extra spaces
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        
        if (empty($normalized)) {
            return null;
        }
        
        $salt = self::get_salt();
        return hash('sha256', $normalized . $salt);
    }
    
    /**
     * Generate hash for address line
     * 
     * @param string $address
     * @return string SHA256 hash
     */
    public static function hash_address($address) {
        if (empty($address)) {
            return null;
        }
        
        // Normalize: lowercase, trim, remove extra spaces
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $address)));
        
        if (empty($normalized)) {
            return null;
        }
        
        $salt = self::get_salt();
        return hash('sha256', $normalized . $salt);
    }
    
    /**
     * Hash all customer PII in an order data array
     * 
     * This replaces raw PII with hashed values for GDPR compliance.
     * 
     * @param array $order_data Order data array with raw PII
     * @return array Order data array with hashed PII
     */
    public static function hash_order_data($order_data) {
        $hashed_data = $order_data;
        
        // Hash email (required for customer_hash)
        if (!empty($order_data['customer_email'])) {
            $hashed_data['customer_hash'] = self::hash_email($order_data['customer_email']);
            // Remove raw email - API should not receive it
            unset($hashed_data['customer_email']);
        }
        
        // Hash phone
        if (!empty($order_data['customer_phone'])) {
            $hashed_data['customer_phone_hash'] = self::hash_phone($order_data['customer_phone']);
            // Remove raw phone
            unset($hashed_data['customer_phone']);
        }
        
        // Hash name
        if (!empty($order_data['customer_name'])) {
            $hashed_data['customer_name_hash'] = self::hash_name($order_data['customer_name']);
            // Remove raw name
            unset($hashed_data['customer_name']);
        }
        
        // Hash address lines
        if (!empty($order_data['shipping_address_line1'])) {
            $hashed_data['shipping_address_line1_hash'] = self::hash_address($order_data['shipping_address_line1']);
            unset($hashed_data['shipping_address_line1']);
        }
        
        if (!empty($order_data['shipping_address_line2'])) {
            $hashed_data['shipping_address_line2_hash'] = self::hash_address($order_data['shipping_address_line2']);
            unset($hashed_data['shipping_address_line2']);
        }
        
        // City, postcode, country can stay (not considered high-risk PII for cross-shop matching)
        // But if you want full anonymization, hash them too
        
        return $hashed_data;
    }
}

