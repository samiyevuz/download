<?php

namespace App\Utils;

use Illuminate\Support\Facades\Log;

/**
 * Media Converter Utility
 * Converts WebP images to JPG using PHP GD (no sudo required)
 */
class MediaConverter
{
    /**
     * Convert WebP image to JPG
     * 
     * @param string $webpPath Path to WebP file
     * @param int $quality JPG quality (1-100, default: 90)
     * @return string|null Path to converted JPG file, or null on failure
     */
    public static function convertWebpToJpg(string $webpPath, int $quality = 90): ?string
    {
        try {
            // Check if file exists
            if (!file_exists($webpPath)) {
                Log::warning('WebP file does not exist', ['path' => $webpPath]);
                return null;
            }

            // Check if GD extension is available
            if (!extension_loaded('gd')) {
                Log::warning('GD extension not available, cannot convert webp');
                return null;
            }

            // Check if WebP support is available in GD
            if (!function_exists('imagecreatefromwebp')) {
                Log::warning('WebP support not available in GD');
                return null;
            }

            // Create image from WebP
            $image = @imagecreatefromwebp($webpPath);
            if ($image === false) {
                Log::warning('Failed to create image from WebP', ['path' => $webpPath]);
                return null;
            }

            // Generate output path (replace .webp with .jpg)
            $outputPath = preg_replace('/\.webp$/i', '.jpg', $webpPath);
            
            // Ensure quality is in valid range
            $quality = max(1, min(100, $quality));

            // Convert to JPG
            $success = @imagejpeg($image, $outputPath, $quality);
            imagedestroy($image);

            if (!$success || !file_exists($outputPath)) {
                Log::warning('Failed to save converted JPG', ['path' => $outputPath]);
                return null;
            }

            Log::info('WebP converted to JPG', [
                'original' => basename($webpPath),
                'converted' => basename($outputPath),
                'original_size' => filesize($webpPath),
                'converted_size' => filesize($outputPath),
                'quality' => $quality,
            ]);

            return $outputPath;
        } catch (\Exception $e) {
            Log::error('Exception converting WebP to JPG', [
                'path' => $webpPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convert image if needed (WebP to JPG)
     * Returns converted path or original path if no conversion needed
     * 
     * @param string $imagePath Path to image file
     * @return string Path to image file (converted or original)
     */
    public static function convertImageIfNeeded(string $imagePath): string
    {
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        
        if ($extension === 'webp') {
            $converted = self::convertWebpToJpg($imagePath);
            if ($converted !== null) {
                return $converted;
            }
            // If conversion fails, return original (will try to send as-is)
            Log::warning('WebP conversion failed, using original', ['path' => $imagePath]);
        }
        
        return $imagePath;
    }

    /**
     * Check if GD extension and WebP support are available
     * 
     * @return bool
     */
    public static function isWebpConversionAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromwebp');
    }
}
