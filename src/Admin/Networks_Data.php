<?php

namespace AEBG\Admin;

/**
 * Networks Data Class
 * 
 * Provides comprehensive network information organized by country
 * with search and filtering capabilities
 */
class Networks_Data {
    
    /**
     * Get all available networks organized by country
     * 
     * @return array
     */
    public static function get_networks_by_country() {
        return [
            'Denmark' => [
                'dk' => 'Denmark',
                'dk_amazon' => 'Amazon Denmark',
                'partner_ads_dk' => 'Partner-Ads Denmark',
                'dk_elgiganten' => 'Elgiganten',
                'dk_power' => 'Power',
                'dk_komplett' => 'Komplett',
                'dk_proshop' => 'Proshop',
                'dk_computersalg' => 'Computersalg',
                'dk_avxperten' => 'AVXperten',
                'dk_avcables' => 'AVCables',
                'dk_hifiklubben' => 'Hifiklubben',
                'dk_avstore' => 'AVStore',
            ],
            'Sweden' => [
                'se' => 'Sweden',
                'se_amazon' => 'Amazon Sweden',
                'se_elgiganten' => 'Elgiganten Sweden',
                'se_komplett' => 'Komplett Sweden',
                'se_proshop' => 'Proshop Sweden',
                'se_webhallen' => 'Webhallen',
                'se_netonnet' => 'NetOnNet',
                'se_elgiganten' => 'Elgiganten',
                'se_komplett' => 'Komplett',
                'se_proshop' => 'Proshop',
                'se_webhallen' => 'Webhallen',
                'se_netonnet' => 'NetOnNet',
            ],
            'Norway' => [
                'no' => 'Norway',
                'no_amazon' => 'Amazon Norway',
                'no_elgiganten' => 'Elgiganten Norway',
                'no_komplett' => 'Komplett Norway',
                'no_proshop' => 'Proshop Norway',
                'no_netonnet' => 'NetOnNet Norway',
                'no_elgiganten' => 'Elgiganten',
                'no_komplett' => 'Komplett',
                'no_proshop' => 'Proshop',
                'no_netonnet' => 'NetOnNet',
            ],
            'Finland' => [
                'fi' => 'Finland',
                'fi_amazon' => 'Amazon Finland',
                'fi_verkkokauppa' => 'Verkkokauppa',
                'fi_jimms' => 'Jimm\'s',
                'fi_multitronic' => 'Multitronic',
                'fi_verkkokauppa' => 'Verkkokauppa',
                'fi_jimms' => 'Jimm\'s',
                'fi_multitronic' => 'Multitronic',
            ],
            'Germany' => [
                'de' => 'Germany',
                'de_amazon' => 'Amazon Germany',
                'de_otto' => 'Otto',
                'de_idealo' => 'Idealo',
                'de_geizhals' => 'Geizhals',
                'de_billiger' => 'Billiger',
                'de_preisvergleich' => 'Preisvergleich',
                'de_otto' => 'Otto',
                'de_idealo' => 'Idealo',
                'de_geizhals' => 'Geizhals',
                'de_billiger' => 'Billiger',
                'de_preisvergleich' => 'Preisvergleich',
            ],
            'Netherlands' => [
                'nl' => 'Netherlands',
                'nl_amazon' => 'Amazon Netherlands',
                'nl_bol' => 'Bol.com',
                'nl_coolblue' => 'Coolblue',
                'nl_mediamarkt' => 'MediaMarkt',
                'nl_bol' => 'Bol.com',
                'nl_coolblue' => 'Coolblue',
                'nl_mediamarkt' => 'MediaMarkt',
            ],
            'Belgium' => [
                'be' => 'Belgium',
                'be_amazon' => 'Amazon Belgium',
                'be_bol' => 'Bol.com Belgium',
                'be_coolblue' => 'Coolblue Belgium',
                'be_mediamarkt' => 'MediaMarkt Belgium',
                'be_bol' => 'Bol.com',
                'be_coolblue' => 'Coolblue',
                'be_mediamarkt' => 'MediaMarkt',
            ],
            'France' => [
                'fr' => 'France',
                'fr_amazon' => 'Amazon France',
                'fr_cdiscount' => 'Cdiscount',
                'fr_fnac' => 'Fnac',
                'fr_darty' => 'Darty',
                'fr_boulanger' => 'Boulanger',
                'fr_cdiscount' => 'Cdiscount',
                'fr_fnac' => 'Fnac',
                'fr_darty' => 'Darty',
                'fr_boulanger' => 'Boulanger',
            ],
            'Italy' => [
                'it' => 'Italy',
                'it_amazon' => 'Amazon Italy',
                'it_ebay' => 'eBay Italy',
                'it_subito' => 'Subito',
                'it_ebay' => 'eBay',
                'it_subito' => 'Subito',
            ],
            'Spain' => [
                'es' => 'Spain',
                'es_amazon' => 'Amazon Spain',
                'es_elcorteingles' => 'El Corte Inglés',
                'es_mediamarkt' => 'MediaMarkt Spain',
                'es_elcorteingles' => 'El Corte Inglés',
                'es_mediamarkt' => 'MediaMarkt',
            ],
            'United Kingdom' => [
                'uk' => 'United Kingdom',
                'uk_amazon' => 'Amazon UK',
                'uk_ebay' => 'eBay UK',
                'uk_argos' => 'Argos',
                'uk_currys' => 'Currys',
                'uk_ao' => 'AO.com',
                'uk_very' => 'Very',
                'uk_ebay' => 'eBay',
                'uk_argos' => 'Argos',
                'uk_currys' => 'Currys',
                'uk_ao' => 'AO.com',
                'uk_very' => 'Very',
            ],
            'United States' => [
                'us' => 'United States',
                'us_amazon' => 'Amazon US',
                'us_ebay' => 'eBay US',
                'us_walmart' => 'Walmart',
                'us_target' => 'Target',
                'us_bestbuy' => 'Best Buy',
                'us_newegg' => 'Newegg',
                'us_ebay' => 'eBay',
                'us_walmart' => 'Walmart',
                'us_target' => 'Target',
                'us_bestbuy' => 'Best Buy',
                'us_newegg' => 'Newegg',
            ],
            'Canada' => [
                'ca' => 'Canada',
                'ca_amazon' => 'Amazon Canada',
                'ca_ebay' => 'eBay Canada',
                'ca_walmart' => 'Walmart Canada',
                'ca_bestbuy' => 'Best Buy Canada',
                'ca_canadacomputers' => 'Canada Computers',
                'ca_ebay' => 'eBay',
                'ca_walmart' => 'Walmart',
                'ca_bestbuy' => 'Best Buy',
                'ca_canadacomputers' => 'Canada Computers',
            ],
            'Australia' => [
                'au' => 'Australia',
                'au_amazon' => 'Amazon Australia',
                'au_ebay' => 'eBay Australia',
                'au_harveynorman' => 'Harvey Norman',
                'au_jbhifi' => 'JB Hi-Fi',
                'au_ebay' => 'eBay',
                'au_harveynorman' => 'Harvey Norman',
                'au_jbhifi' => 'JB Hi-Fi',
            ],
            'Global' => [
                'global_amazon' => 'Amazon Global',
                'global_ebay' => 'eBay Global',
                'global_aliexpress' => 'AliExpress',
                'global_wish' => 'Wish',
                'global_etsy' => 'Etsy',
                'global_amazon' => 'Amazon',
                'global_ebay' => 'eBay',
                'global_aliexpress' => 'AliExpress',
                'global_wish' => 'Wish',
                'global_etsy' => 'Etsy',
            ]
        ];
    }
    
    /**
     * Get all networks as a flat array for JavaScript consumption
     * 
     * @return array
     */
    public static function get_all_networks() {
        global $wpdb;
        
        // Try to get networks from the database first
        $db_networks = self::get_networks_from_database();
        
        if (!empty($db_networks)) {
            return $db_networks;
        }
        
        // Fallback to hardcoded data if database is empty
        $networks = [];
        $networks_by_country = self::get_networks_by_country();
        $popular_networks = self::get_popular_networks();
        
        foreach ($networks_by_country as $country => $country_networks) {
            foreach ($country_networks as $code => $name) {
                // Determine if this is a popular network
                $is_popular = array_key_exists($code, $popular_networks);
                
                // Determine category based on code
                $category = 'affiliate';
                if (strpos($code, 'amazon') !== false) {
                    $category = 'amazon';
                } elseif (strpos($code, 'ebay') !== false) {
                    $category = 'marketplace';
                } elseif (strpos($code, 'elgiganten') !== false || strpos($code, 'power') !== false || strpos($code, 'komplett') !== false) {
                    $category = 'electronics';
                }
                
                // Get country code for flag display
                $country_code = self::get_country_code($country);
                
                $networks[] = [
                    'code' => $code,
                    'name' => $name,
                    'country' => $country_code,
                    'countryName' => $country,
                    'popular' => $is_popular,
                    'category' => $category
                ];
            }
        }
        
        return $networks;
    }
    
    /**
     * Get networks from the actual database table
     * 
     * @return array
     */
    private static function get_networks_from_database() {
        global $wpdb;
        
        try {
            // Check if the enhanced table exists first
            $enhanced_table = $wpdb->prefix . 'aebg_networks_enhanced';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$enhanced_table}'") === $enhanced_table;
            
            if ($table_exists) {
                // Use enhanced table if available
                // Check if is_active column exists
                $columns = $wpdb->get_results("SHOW COLUMNS FROM {$enhanced_table} LIKE 'is_active'");
                $has_is_active = !empty($columns);
                
                $where_clause = $has_is_active ? "WHERE is_active = 1" : "";
                
                $results = $wpdb->get_results(
                    "SELECT network_key as code, network_name as name, country, country_name as countryName, 
                            is_popular as popular, category, region, flag
                     FROM {$enhanced_table} 
                     {$where_clause}
                     ORDER BY sort_order ASC, network_name ASC",
                    ARRAY_A
                );
                
                if ($wpdb->last_error) {
                    error_log('AEBG Networks_Data: Enhanced table query error: ' . $wpdb->last_error);
                }
            } else {
                // Fallback to original table
                $original_table = $wpdb->prefix . 'aebg_networks';
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$original_table}'") === $original_table;
                
                if ($table_exists) {
                    // Check which columns exist
                    $all_columns = $wpdb->get_results("SHOW COLUMNS FROM {$original_table}", ARRAY_A);
                    $column_names = array_column($all_columns, 'Field');
                    
                    $has_is_active = in_array('is_active', $column_names);
                    $has_is_popular = in_array('is_popular', $column_names);
                    $has_category = in_array('category', $column_names);
                    $has_region = in_array('region', $column_names);
                    $has_flag = in_array('flag', $column_names);
                    
                    $where_clause = $has_is_active ? "WHERE is_active = 1" : "";
                    
                    // Build SELECT with only existing columns
                    $select_fields = "network_key as code, network_name as name, country, 
                                CASE 
                                    WHEN country = 'DK' THEN 'Denmark'
                                    WHEN country = 'FI' THEN 'Finland'
                                    WHEN country = 'DE' THEN 'Germany'
                                    WHEN country = 'NL' THEN 'Netherlands'
                                    WHEN country = 'BE' THEN 'Belgium'
                                    WHEN country = 'FR' THEN 'France'
                                    WHEN country = 'IT' THEN 'Italy'
                                    WHEN country = 'ES' THEN 'Spain'
                                    WHEN country = 'UK' THEN 'United Kingdom'
                                    WHEN country = 'US' THEN 'United States'
                                    ELSE 'Global'
                                END as countryName";
                    
                    if ($has_is_popular) {
                        $select_fields .= ", is_popular as popular";
                    } else {
                        $select_fields .= ", 0 as popular";
                    }
                    
                    if ($has_category) {
                        $select_fields .= ", category";
                    } else {
                        $select_fields .= ", 'affiliate' as category";
                    }
                    
                    if ($has_region) {
                        $select_fields .= ", region";
                    } else {
                        $select_fields .= ", NULL as region";
                    }
                    
                    if ($has_flag) {
                        $select_fields .= ", flag";
                    } else {
                        $select_fields .= ", '' as flag";
                    }
                    
                    $results = $wpdb->get_results(
                        "SELECT {$select_fields}
                         FROM {$original_table} 
                         {$where_clause}
                         ORDER BY network_name ASC",
                        ARRAY_A
                    );
                    
                    if ($wpdb->last_error) {
                        error_log('AEBG Networks_Data: Original table query error: ' . $wpdb->last_error);
                    }
                } else {
                    error_log('AEBG Networks_Data: Neither enhanced nor original table exists');
                    return [];
                }
            }
            
            // Log what we found
            if (empty($results)) {
                error_log('AEBG Networks_Data: No results from database query');
                return [];
            }
            
            error_log('AEBG Networks_Data: Found ' . count($results) . ' networks in database');
            
            // Transform results to match expected format
            $networks = [];
            foreach ($results as $network) {
                $networks[] = [
                    'code' => $network['code'],
                    'name' => $network['name'],
                    'country' => $network['country'] ?? 'GL',
                    'countryName' => $network['countryName'] ?? 'Global',
                    'popular' => (bool)($network['popular'] ?? false),
                    'category' => $network['category'] ?? 'affiliate',
                    'region' => $network['region'] ?? '',
                    'flag' => $network['flag'] ?? ''
                ];
            }
            
            return $networks;
            
        } catch (Exception $e) {
            error_log('AEBG Networks_Data: Error reading from database: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get country code for flag display
     * 
     * @param string $country_name
     * @return string
     */
    private static function get_country_code($country_name) {
        $country_map = [
            'Denmark' => 'DK',
            'Sweden' => 'SE',
            'Norway' => 'NO',
            'Finland' => 'FI',
            'Germany' => 'DE',
            'Netherlands' => 'NL',
            'Belgium' => 'BE',
            'France' => 'FR',
            'Italy' => 'IT',
            'Spain' => 'ES',
            'United Kingdom' => 'UK',
            'United States' => 'US',
            'Global' => 'GL'
        ];
        
        return $country_map[$country_name] ?? 'GL';
    }
    
    /**
     * Search networks by name or country
     * 
     * @param string $query
     * @return array
     */
    public static function search_networks($query) {
        $query = strtolower(trim($query));
        if (empty($query)) {
            return self::get_networks_by_country();
        }
        
        $results = [];
        $networks_by_country = self::get_networks_by_country();
        
        foreach ($networks_by_country as $country => $country_networks) {
            $country_results = [];
            foreach ($country_networks as $code => $name) {
                if (strpos(strtolower($name), $query) !== false || 
                    strpos(strtolower($country), $query) !== false ||
                    strpos(strtolower($code), $query) !== false) {
                    $country_results[$code] = $name;
                }
            }
            if (!empty($country_results)) {
                $results[$country] = $country_results;
            }
        }
        
        return $results;
    }
    
    /**
     * Get networks by specific country
     * 
     * @param string $country
     * @return array
     */
    public static function get_networks_by_specific_country($country) {
        $networks_by_country = self::get_networks_by_country();
        return $networks_by_country[$country] ?? [];
    }
    
    /**
     * Get popular networks for quick selection
     * 
     * @return array
     */
    public static function get_popular_networks() {
        return [
            'dk_amazon' => 'Amazon Denmark',
            'partner_ads_dk' => 'Partner-Ads Denmark',
            'dk_elgiganten' => 'Elgiganten',
            'dk_power' => 'Power',
            'se_amazon' => 'Amazon Sweden',
            'se_elgiganten' => 'Elgiganten Sweden',
            'no_amazon' => 'Amazon Norway',
            'no_elgiganten' => 'Elgiganten Norway',
            'de_amazon' => 'Amazon Germany',
            'uk_amazon' => 'Amazon UK',
            'us_amazon' => 'Amazon US',
            'global_amazon' => 'Amazon Global',
            'global_ebay' => 'eBay Global'
        ];
    }
} 