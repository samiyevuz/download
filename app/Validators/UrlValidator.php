<?php

namespace App\Validators;

use Illuminate\Support\Facades\Log;

/**
 * URL Validator for Instagram and TikTok links
 */
class UrlValidator
{
    /**
     * Allowed domains for media downloads
     */
    private const ALLOWED_DOMAINS = [
        'instagram.com',
        'www.instagram.com',
        'tiktok.com',
        'www.tiktok.com',
    ];

    /**
     * Validate if URL is a valid Instagram or TikTok link
     *
     * @param string $url
     * @return bool
     */
    public function isValid(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        // Parse URL
        $parsed = parse_url($url);
        
        if ($parsed === false || !isset($parsed['host'])) {
            return false;
        }

        // Normalize host (remove www. prefix for comparison)
        $host = strtolower($parsed['host']);
        $host = preg_replace('/^www\./', '', $host);

        // Check if host is in allowed domains
        foreach (self::ALLOWED_DOMAINS as $allowedDomain) {
            $normalizedAllowed = preg_replace('/^www\./', '', strtolower($allowedDomain));
            if ($host === $normalizedAllowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize URL to prevent command injection
     *
     * @param string $url
     * @return string
     */
    public function sanitize(string $url): string
    {
        // Remove any potentially dangerous characters
        $url = trim($url);
        
        // Ensure it starts with http:// or https://
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        // Validate URL format
        $url = filter_var($url, FILTER_SANITIZE_URL);
        
        return $url;
    }

    /**
     * Validate and sanitize URL
     *
     * @param string $url
     * @return string|null Returns sanitized URL if valid, null otherwise
     */
    public function validateAndSanitize(string $url): ?string
    {
        $sanitized = $this->sanitize($url);
        
        if (!$this->isValid($sanitized)) {
            return null;
        }

        return $sanitized;
    }
}
