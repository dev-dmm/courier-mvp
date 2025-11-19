<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

class CustomerHashService
{
    /**
     * Generate customer hash from email (GDPR-compliant pseudonymization)
     * 
     * Uses a global salt to ensure same email produces same hash across all shops.
     * This enables cross-shop customer matching without storing raw PII.
     * 
     * @param string $email
     * @return string
     */
    public function generateHash(string $email): string
    {
        // Normalize email: lowercase and trim
        $normalized = strtolower(trim($email));
        
        // Get global salt from config (must be same across all installations)
        $salt = Config::get('app.customer_hash_salt');
        
        if (empty($salt) || $salt === 'change-this-to-a-secure-random-string-min-32-chars') {
            throw new \RuntimeException('CUSTOMER_HASH_SALT must be set in .env file for GDPR compliance');
        }
        
        // Generate SHA256 hash with salt: SHA256(email + salt)
        return hash('sha256', $normalized . $salt);
    }

    /**
     * Generate hash for phone number
     * 
     * @param string $phone
     * @return string
     */
    public function generatePhoneHash(string $phone): string
    {
        // Normalize phone: remove spaces, dashes, parentheses
        $normalized = preg_replace('/[\s\-\(\)]/', '', trim($phone));
        
        $salt = Config::get('app.customer_hash_salt');
        
        if (empty($salt) || $salt === 'change-this-to-a-secure-random-string-min-32-chars') {
            throw new \RuntimeException('CUSTOMER_HASH_SALT must be set in .env file for GDPR compliance');
        }
        
        return hash('sha256', $normalized . $salt);
    }

    /**
     * Generate hash for name
     * 
     * @param string $name
     * @return string
     */
    public function generateNameHash(string $name): string
    {
        // Normalize name: lowercase, trim, remove extra spaces
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        
        $salt = Config::get('app.customer_hash_salt');
        
        if (empty($salt) || $salt === 'change-this-to-a-secure-random-string-min-32-chars') {
            throw new \RuntimeException('CUSTOMER_HASH_SALT must be set in .env file for GDPR compliance');
        }
        
        return hash('sha256', $normalized . $salt);
    }

    /**
     * Generate hash for address
     * 
     * @param string $address
     * @return string
     */
    public function generateAddressHash(string $address): string
    {
        // Normalize address: lowercase, trim, remove extra spaces
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $address)));
        
        $salt = Config::get('app.customer_hash_salt');
        
        if (empty($salt) || $salt === 'change-this-to-a-secure-random-string-min-32-chars') {
            throw new \RuntimeException('CUSTOMER_HASH_SALT must be set in .env file for GDPR compliance');
        }
        
        return hash('sha256', $normalized . $salt);
    }

    /**
     * Validate email format
     * 
     * @param string $email
     * @return bool
     */
    public function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

