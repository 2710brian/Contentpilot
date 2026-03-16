<?php
/**
 * Settings Tab: Advanced
 * 
 * Contains: Advanced Settings, Duplicate Detection, Merchant Discovery, Price Comparison, Action Scheduler
 * 
 * @package AEBG
 */
?>
<div class="aebg-settings-grid">
    <!-- Advanced Settings -->
    <div class="aebg-settings-card">
        <div class="aebg-card-header">
            <h2>⚙️ Advanced Settings</h2>
        </div>
        <div class="aebg-card-content">
            <div class="aebg-form-group">
                <label for="aebg_batch_size">Batch Processing Size</label>
                <input type="number" id="aebg_batch_size" name="aebg_settings[batch_size]" 
                       value="<?php echo esc_attr( isset( $options['batch_size'] ) ? $options['batch_size'] : '5' ); ?>" 
                       class="aebg-input" min="1" max="20">
                <p class="aebg-help-text">
                    <span class="aebg-icon">📦</span>
                    Number of products to process in each batch during price comparison (1-20). Note: Action Scheduler batch size is fixed at 1 for process isolation.
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_delay_between_requests">Delay Between Requests (seconds)</label>
                <input type="number" id="aebg_delay_between_requests" name="aebg_settings[delay_between_requests]" 
                       value="<?php echo esc_attr( isset( $options['delay_between_requests'] ) ? $options['delay_between_requests'] : '1' ); ?>" 
                       class="aebg-input" min="0" max="10" step="0.5">
                <p class="aebg-help-text">
                    <span class="aebg-icon">⏱️</span>
                    Delay between API requests to avoid rate limiting
                </p>
            </div>

            <!-- Duplicate Detection Settings -->
            <div class="aebg-form-group">
                <h3 style="margin-top: 20px; margin-bottom: 15px; color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 5px;">
                    <span class="aebg-icon">🔍</span>
                    Duplicate Detection Settings
                </h3>
                
                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_enable_duplicate_detection" name="aebg_settings[enable_duplicate_detection]" 
                               value="1" <?php checked( isset( $options['enable_duplicate_detection'] ) ? $options['enable_duplicate_detection'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🔍</span>
                            Enable Duplicate Detection
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🔍</span>
                        Automatically detect and remove duplicate products from different suppliers and color variations
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_prevent_same_product_different_suppliers" name="aebg_settings[prevent_same_product_different_suppliers]" 
                               value="1" <?php checked( isset( $options['prevent_same_product_different_suppliers'] ) ? $options['prevent_same_product_different_suppliers'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🏪</span>
                            Prevent Same Product from Different Suppliers
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🏪</span>
                        Remove duplicate products that are the same item sold by different merchants
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_prevent_same_product_different_colors" name="aebg_settings[prevent_same_product_different_colors]" 
                               value="1" <?php checked( isset( $options['prevent_same_product_different_colors'] ) ? $options['prevent_same_product_different_colors'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🎨</span>
                            Prevent Same Product in Different Colors
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🎨</span>
                        Remove duplicate products that are the same item in different color variations
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg_duplicate_similarity_threshold">Similarity Threshold</label>
                    <div class="aebg-slider-container">
                        <input type="range" id="aebg_duplicate_similarity_threshold" name="aebg_settings[duplicate_similarity_threshold]" 
                               min="0.7" max="0.95" step="0.05" 
                               value="<?php echo esc_attr( isset( $options['duplicate_similarity_threshold'] ) ? $options['duplicate_similarity_threshold'] : '0.85' ); ?>" 
                               class="aebg-slider">
                        <div class="aebg-slider-labels">
                            <span>Loose (0.7)</span>
                            <span id="aebg-similarity-value">0.85</span>
                            <span>Strict (0.95)</span>
                        </div>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🎯</span>
                        How similar products need to be to be considered duplicates (0.7 = loose, 0.95 = strict)
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_prefer_higher_rating_for_duplicates" name="aebg_settings[prefer_higher_rating_for_duplicates]" 
                               value="1" <?php checked( isset( $options['prefer_higher_rating_for_duplicates'] ) ? $options['prefer_higher_rating_for_duplicates'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">⭐</span>
                            Prefer Higher Rated Products
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">⭐</span>
                        When duplicates are found, keep the product with the higher rating
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_prefer_more_reviews_for_duplicates" name="aebg_settings[prefer_more_reviews_for_duplicates]" 
                               value="1" <?php checked( isset( $options['prefer_more_reviews_for_duplicates'] ) ? $options['prefer_more_reviews_for_duplicates'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">📝</span>
                            Prefer Products with More Reviews
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📝</span>
                        When duplicates are found, keep the product with more customer reviews
                    </p>
                </div>
            </div>

            <!-- Merchant Discovery Settings -->
            <div class="aebg-form-group">
                <h3 style="margin-top: 20px; margin-bottom: 15px; color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 5px;">
                    <span class="aebg-icon">🔍</span>
                    Merchant Discovery Settings
                </h3>
                
                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_enable_merchant_discovery" name="aebg_settings[enable_merchant_discovery]" 
                               value="1" <?php checked( isset( $options['enable_merchant_discovery'] ) ? $options['enable_merchant_discovery'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🔍</span>
                            Enable Merchant Discovery
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🔍</span>
                        Automatically discover up to 5 merchants for each product during bulk generation (without duplicate/color filtering)
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg_max_merchants_per_product">Maximum Merchants per Product</label>
                    <input type="number" id="aebg_max_merchants_per_product" name="aebg_settings[max_merchants_per_product]" 
                           min="1" max="10" value="<?php echo esc_attr( isset( $options['max_merchants_per_product'] ) ? $options['max_merchants_per_product'] : '5' ); ?>" 
                           class="aebg-input">
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🏪</span>
                        Maximum number of merchants to discover for each product (1-10)
                    </p>
                </div>
            </div>

            <!-- Price Comparison Settings -->
            <div class="aebg-form-group">
                <h3 style="margin-top: 20px; margin-bottom: 15px; color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 5px;">
                    <span class="aebg-icon">💰</span>
                    Price Comparison Settings
                </h3>
                
                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_enable_price_comparison" name="aebg_settings[enable_price_comparison]" 
                               value="1" <?php checked( isset( $options['enable_price_comparison'] ) ? $options['enable_price_comparison'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">💰</span>
                            Enable Price Comparison
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">💰</span>
                        Process price comparison data for discovered merchants
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg_min_merchant_count">Minimum Merchant Count</label>
                    <input type="number" id="aebg_min_merchant_count" name="aebg_settings[min_merchant_count]" 
                           min="1" max="10" value="<?php echo esc_attr( isset( $options['min_merchant_count'] ) ? $options['min_merchant_count'] : '2' ); ?>" 
                           class="aebg-input">
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📊</span>
                        Minimum number of merchants required for price comparison analysis
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg_max_merchant_count">Maximum Merchant Count</label>
                    <input type="number" id="aebg_max_merchant_count" name="aebg_settings[max_merchant_count]" 
                           min="2" max="20" value="<?php echo esc_attr( isset( $options['max_merchant_count'] ) ? $options['max_merchant_count'] : '10' ); ?>" 
                           class="aebg-input">
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📊</span>
                        Maximum number of merchants to include in price comparison analysis
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_prefer_lower_prices" name="aebg_settings[prefer_lower_prices]" 
                               value="1" <?php checked( isset( $options['prefer_lower_prices'] ) ? $options['prefer_lower_prices'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">💲</span>
                            Prefer Lower Prices
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">💲</span>
                        Prioritize merchants with lower prices in comparison analysis
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_prefer_higher_ratings" name="aebg_settings[prefer_higher_ratings]" 
                               value="1" <?php checked( isset( $options['prefer_higher_ratings'] ) ? $options['prefer_higher_ratings'] : true ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">⭐</span>
                            Prefer Higher Ratings
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">⭐</span>
                        Prioritize merchants with higher ratings in comparison analysis
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg_price_variance_threshold">Price Variance Threshold</label>
                    <div class="aebg-slider-container">
                        <input type="range" id="aebg_price_variance_threshold" name="aebg_settings[price_variance_threshold]" 
                               min="0.1" max="0.5" step="0.05" 
                               value="<?php echo esc_attr( isset( $options['price_variance_threshold'] ) ? $options['price_variance_threshold'] : '0.3' ); ?>" 
                               class="aebg-slider">
                        <div class="aebg-slider-labels">
                            <span>Low (0.1)</span>
                            <span id="aebg-variance-value">0.3</span>
                            <span>High (0.5)</span>
                        </div>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📈</span>
                        Maximum acceptable price variance between merchants (0.1 = low variance, 0.5 = high variance)
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_filter_by_price_comparison" name="aebg_settings[filter_by_price_comparison]" 
                               value="1" <?php checked( isset( $options['filter_by_price_comparison'] ) ? $options['filter_by_price_comparison'] : false ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🔍</span>
                            Filter by Price Comparison
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🔍</span>
                        Filter products based on price comparison criteria (may reduce final product count)
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" id="aebg_sort_by_price_comparison" name="aebg_settings[sort_by_price_comparison]" 
                               value="1" <?php checked( isset( $options['sort_by_price_comparison'] ) ? $options['sort_by_price_comparison'] : false ); ?>>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">📊</span>
                            Sort by Price Comparison
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📊</span>
                        Sort products based on price comparison criteria (merchant count, price variance, etc.)
                    </p>
                </div>
            </div>

            <div class="aebg-form-group">
                <label>Action Scheduler Management</label>
                <div class="aebg-button-group">
                    <button type="button" id="aebg-reset-action-scheduler" class="aebg-btn aebg-btn-warning">
                        <span class="aebg-icon">🔄</span>
                        Reset Action Scheduler
                    </button>
                    <button type="button" id="aebg-trigger-action-scheduler" class="aebg-btn aebg-btn-info">
                        <span class="aebg-icon">▶️</span>
                        Trigger Pending Actions
                    </button>
                </div>
                <p class="aebg-help-text">
                    <span class="aebg-icon">⚠️</span>
                    Reset: Clear all pending and completed action scheduler tasks. Trigger: Manually run pending tasks if they're stuck.
                </p>
            </div>
        </div>
    </div>
</div>

