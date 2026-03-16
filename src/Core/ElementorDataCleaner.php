<?php

namespace AEBG\Core;


/**
 * Elementor Data Cleaner Trait
 * 
 * Shared methods for cleaning and fixing Elementor data structures.
 * Used by Generator, TemplateManager, AIPromptProcessor, and Meta_Box.
 * 
 * @package AEBG\Core
 */
trait ElementorDataCleaner {
    
    /**
     * Clean Elementor data for JSON encoding
     * 
     * Recursively cleans Elementor data structure to ensure proper JSON encoding.
     * Optionally fixes URLs in the data structure.
     * 
     * @param mixed $elementor_data The Elementor data to clean
     * @param bool $fix_urls Whether to fix URLs in the data (default: true)
     * @return mixed The cleaned Elementor data
     */
    protected function cleanElementorDataForEncoding($elementor_data, $fix_urls = true) {
        if (!is_array($elementor_data)) {
            return $elementor_data;
        }
        
        // First, fix any URLs in the data structure (only on first call, not recursively)
        if ($fix_urls && method_exists($this, 'fixUrlsInElementorData')) {
            $elementor_data = $this->fixUrlsInElementorData($elementor_data);
        }
        
        $cleaned = [];
        foreach ($elementor_data as $key => $value) {
            if (is_array($value)) {
                // Don't fix URLs recursively - they're already fixed
                $cleaned[$key] = $this->cleanElementorDataForEncoding($value, false);
            } else if (is_string($value)) {
                // Clean string values that might contain unescaped quotes
                $cleaned[$key] = $this->cleanStringForJson($value);
            } else {
                $cleaned[$key] = $value;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Recursively fix malformed URLs in Elementor data structure
     * 
     * This ensures URLs are fixed before being saved to Elementor post meta.
     * 
     * @param mixed $data The Elementor data to process
     * @return mixed The data with fixed URLs
     */
    protected function fixUrlsInElementorData($data) {
        if (!is_array($data)) {
            // If it's a string and looks like a URL, fix it
            if (is_string($data) && (preg_match('/^(https?:\/\/|s:\/\/|s\/\/|http:\/\/s:\/\/|https:\/\/s:\/\/|http:\/\/s\/\/|https:\/\/s\/\/)/', $data) || filter_var($data, FILTER_VALIDATE_URL) !== false)) {
                // URLs from Datafeedr are already correctly formatted
                return $data;
            }
            return $data;
        }
        
        $fixed = [];
        foreach ($data as $key => $value) {
            // Check for common URL field names in Elementor
            if (($key === 'url' || $key === 'href') && is_string($value)) {
                // This is a URL field, fix it
                // URLs from Datafeedr are already correctly formatted
                $fixed[$key] = $value;
            } elseif (is_array($value) && isset($value['url']) && is_string($value['url'])) {
                // This is a nested URL structure (like link.url or image.url)
                $fixed_value = $value;
                // URLs from Datafeedr are already correctly formatted
                $fixed_value['url'] = $value['url'];
                $fixed[$key] = $this->fixUrlsInElementorData($fixed_value);
            } else {
                // Recursively process nested arrays
                $fixed[$key] = $this->fixUrlsInElementorData($value);
            }
        }
        
        return $fixed;
    }
    
    /**
     * Clean a string value for JSON encoding
     * 
     * Removes control characters and properly escapes the string for JSON.
     * 
     * @param string $string The string to clean
     * @return string The cleaned string
     */
    protected function cleanStringForJson($string) {
        if (!is_string($string)) {
            return $string;
        }
        
        // First, handle any control characters that could break JSON
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $string);
        
        // Use json_encode to properly escape the string, then remove the surrounding quotes
        $json_encoded = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json_encoded === false) {
            // If json_encode fails, fall back to manual escaping
            $cleaned = str_replace('"', '\\"', $cleaned);
            $cleaned = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $cleaned);
            return $cleaned;
        }
        
        // Remove the surrounding quotes that json_encode adds
        $cleaned = substr($json_encoded, 1, -1);
        
        return $cleaned;
    }
}

