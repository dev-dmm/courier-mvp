<?php

namespace App\Services;

class CustomerHashService
{
    /**
     * Generate customer hash from email
     * 
     * @param string $email
     * @return string
     */
    public function generateHash(string $email): string
    {
        // Normalize email: lowercase and trim
        $normalized = strtolower(trim($email));
        
        // Generate SHA256 hash
        return hash('sha256', $normalized);
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

