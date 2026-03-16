<?php

namespace AEBG\Core;

/**
 * Comparison Manager Class
 * 
 * Handles storing and retrieving comparison data from the database
 * 
 * @package AEBG\Core
 */
class ComparisonManager {
    
    /**
     * Save comparison data to database
     * 
     * @param int $user_id User ID
     * @param int|null $post_id Post ID (optional)
     * @param string $product_id Product ID
     * @param string $comparison_name Comparison name
     * @param array $comparison_data Comparison data
     * @return bool|int Returns comparison ID on success, false on failure
     */
    public static function save_comparison($user_id, $post_id, $product_id, $comparison_name, $comparison_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        error_log('[AEBG] 💾 DATABASE SAVE: Saving comparison data for product ' . $product_id . ' (User: ' . $user_id . ', Post: ' . ($post_id ?? 'NULL') . ')');
        error_log('[AEBG] 📊 SAVE DATA: ' . count($comparison_data['merchants'] ?? []) . ' merchants, price range: ' . ($comparison_data['price_range']['lowest'] ?? 'N/A') . ' - ' . ($comparison_data['price_range']['highest'] ?? 'N/A'));
        
        // Prepare the data
        $data = [
            'user_id' => intval($user_id),
            'post_id' => $post_id ? intval($post_id) : null,
            'product_id' => sanitize_text_field($product_id),
            'comparison_name' => sanitize_text_field($comparison_name),
            'comparison_data' => json_encode($comparison_data),
            'status' => 'active',
            'updated_at' => current_time('mysql')
        ];
        
        // Check if comparison already exists for this user, post, and product
        if ($post_id) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE user_id = %d AND post_id = %d AND product_id = %s",
                $user_id,
                $post_id,
                $product_id
            ));
        } else {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE user_id = %d AND post_id IS NULL AND product_id = %s",
                $user_id,
                $product_id
            ));
        }
        
        if ($existing) {
            // Update existing comparison
            error_log('[AEBG] 🔄 DATABASE UPDATE: Updating existing comparison ID ' . $existing->id . ' for product ' . $product_id);
            $result = $wpdb->update(
                $table_name,
                $data,
                ['id' => $existing->id]
            );
            
            if ($result === false) {
                error_log('[AEBG] ❌ DATABASE ERROR: Failed to update comparison: ' . $wpdb->last_error);
            } else {
                error_log('[AEBG] ✅ DATABASE SUCCESS: Updated comparison ID ' . $existing->id . ' for product ' . $product_id);
            }
            
            return $result !== false ? $existing->id : false;
        } else {
            // Insert new comparison
            error_log('[AEBG] ➕ DATABASE INSERT: Creating new comparison for product ' . $product_id);
            $data['created_at'] = current_time('mysql');
            
            $result = $wpdb->insert($table_name, $data);
            
            if ($result === false) {
                $error = $wpdb->last_error;
                error_log('[AEBG] ❌ DATABASE ERROR: Failed to insert comparison: ' . $error);
                
                // Check if this is a duplicate key error (old schema issue)
                if (strpos($error, 'Duplicate entry') !== false && strpos($error, 'idx_user_product') !== false) {
                    error_log('[AEBG] Database schema issue detected - old unique key still exists');
                    error_log('[AEBG] Attempting to fix this automatically...');
                    
                    // Try to drop the old index and add the new one
                    $drop_result = $wpdb->query("ALTER TABLE `{$table_name}` DROP INDEX `idx_user_product`");
                    if ($drop_result !== false) {
                        error_log('[AEBG] Successfully dropped old index');
                        $add_result = $wpdb->query("ALTER TABLE `{$table_name}` ADD UNIQUE KEY `idx_user_post_product` (`user_id`, `post_id`, `product_id`)");
                        if ($add_result !== false) {
                            error_log('[AEBG] Successfully added new index');
                            // Try the insert again
                            $retry_result = $wpdb->insert($table_name, $data);
                            if ($retry_result !== false) {
                                error_log('[AEBG] Successfully inserted after schema fix');
                                return $wpdb->insert_id;
                            }
                        }
                    }
                    
                    // If automatic fix failed, try to update existing record instead (fallback)
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$table_name} WHERE user_id = %d AND product_id = %s",
                        $user_id,
                        $product_id
                    ));
                    
                    if ($existing) {
                        error_log('[AEBG] Attempting to update existing record as fallback');
                        $update_result = $wpdb->update(
                            $table_name,
                            $data,
                            ['id' => $existing->id]
                        );
                        
                        if ($update_result !== false) {
                            error_log('[AEBG] Successfully updated existing record as fallback');
                            return $existing->id;
                        }
                    }
                }
            } else {
                error_log('[AEBG] ✅ DATABASE SUCCESS: Created new comparison ID ' . $wpdb->insert_id . ' for product ' . $product_id);
            }
            
            return $result !== false ? $wpdb->insert_id : false;
        }
    }
    
    /**
     * Get comparison data from database
     * 
     * @param int $user_id User ID
     * @param int|null $post_id Post ID (optional)
     * @param string $product_id Product ID
     * @return array|false Returns comparison data on success, false on failure
     */
    public static function get_comparison($user_id, $product_id, $post_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        if ($post_id) {
            $comparison = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE user_id = %d AND post_id = %d AND product_id = %s AND status = 'active'",
                $user_id,
                $post_id,
                $product_id
            ));
        } else {
            $comparison = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE user_id = %d AND post_id IS NULL AND product_id = %s AND status = 'active'",
                $user_id,
                $product_id
            ));
        }
        
        if ($comparison) {
            $comparison->comparison_data = json_decode($comparison->comparison_data, true);
            error_log('[AEBG] 📖 DATABASE READ: Retrieved comparison ID ' . $comparison->id . ' for product ' . $product_id . ' (User: ' . $user_id . ', Post: ' . ($post_id ?? 'NULL') . ')');
            return $comparison;
        }
        
        error_log('[AEBG] 📭 DATABASE MISS: No comparison found for product ' . $product_id . ' (User: ' . $user_id . ', Post: ' . ($post_id ?? 'NULL') . ')');
        return false;
    }
    
    /**
     * Get all comparisons for a user
     * 
     * @param int $user_id User ID
     * @param int|null $post_id Post ID (optional)
     * @return array Returns array of comparisons
     */
    public static function get_user_comparisons($user_id, $post_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        $where_clause = "user_id = %d AND status = 'active'";
        $where_values = [$user_id];
        
        if ($post_id) {
            $where_clause .= " AND post_id = %d";
            $where_values[] = $post_id;
        }
        
        $comparisons = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY updated_at DESC",
            ...$where_values
        ));
        
        // Decode JSON data for each comparison
        foreach ($comparisons as $comparison) {
            $comparison->comparison_data = json_decode($comparison->comparison_data, true);
        }
        
        return $comparisons;
    }
    
    /**
     * Delete comparison data
     * 
     * @param int $user_id User ID
     * @param string $product_id Product ID
     * @return bool Returns true on success, false on failure
     */
    public static function delete_comparison($user_id, $product_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        $result = $wpdb->update(
            $table_name,
            ['status' => 'deleted', 'updated_at' => current_time('mysql')],
            ['user_id' => $user_id, 'product_id' => $product_id]
        );
        
        return $result !== false;
    }
    
    /**
     * Delete all comparisons for a user
     * 
     * @param int $user_id User ID
     * @param int|null $post_id Post ID (optional)
     * @return bool Returns true on success, false on failure
     */
    public static function delete_user_comparisons($user_id, $post_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        $where_clause = "user_id = %d";
        $where_values = [$user_id];
        
        if ($post_id) {
            $where_clause .= " AND post_id = %d";
            $where_values[] = $post_id;
        }
        
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET status = 'deleted', updated_at = %s WHERE {$where_clause}",
            current_time('mysql'),
            ...$where_values
        ));
        
        return $result !== false;
    }
    
    /**
     * Clean up old comparisons (older than 30 days)
     * 
     * @return int Returns number of deleted comparisons
     */
    public static function cleanup_old_comparisons() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE updated_at < %s AND status = 'deleted'",
            $thirty_days_ago
        ));
        
        return $result;
    }
    
    /**
     * Test database connection and table existence
     * 
     * @return array Returns test results
     */
    public static function test_database_connection() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aebg_comparisons';
        
        $results = [
            'table_exists' => false,
            'can_insert' => false,
            'can_select' => false,
            'error' => null
        ];
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        $results['table_exists'] = $table_exists;
        
        if (!$table_exists) {
            $results['error'] = 'Table does not exist. Please reactivate the plugin.';
            return $results;
        }
        
        // Test insert
        $test_data = [
            'user_id' => 1,
            'post_id' => null,
            'product_id' => 'test_product_' . time(),
            'comparison_name' => 'Test Comparison',
            'comparison_data' => json_encode(['test' => 'data']),
            'status' => 'active',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];
        
        $insert_result = $wpdb->insert($table_name, $test_data);
        $results['can_insert'] = $insert_result !== false;
        
        if ($insert_result) {
            $insert_id = $wpdb->insert_id;
            
            // Test select
            $select_result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                $insert_id
            ));
            $results['can_select'] = $select_result !== null;
            
            // Clean up test data
            $wpdb->delete($table_name, ['id' => $insert_id]);
        } else {
            $results['error'] = 'Failed to insert test data: ' . $wpdb->last_error;
        }
        
        return $results;
    }
} 