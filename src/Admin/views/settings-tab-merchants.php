<?php
/**
 * Settings Tab: Merchants
 *
 * Manage merchants to exclude from all Datafeedr-based product searches.
 *
 * @package AEBG
 */

// Ensure $options is available from parent settings page
if ( ! isset( $options ) || ! is_array( $options ) ) {
    $options = \AEBG\Admin\Settings::get_settings();
}

// Prepare excluded merchants list for textarea (one per line)
$excluded_merchants = $options['excluded_merchants'] ?? [];
if ( is_string( $excluded_merchants ) ) {
    $excluded_merchants = preg_split( '/[\r\n,]+/', $excluded_merchants );
}
if ( ! is_array( $excluded_merchants ) ) {
    $excluded_merchants = [];
}

// Normalize and remove empties
$excluded_merchants = array_values( array_unique( array_filter( array_map( 'trim', $excluded_merchants ) ) ) );

?>
<div class="aebg-settings-grid">
    <div class="aebg-settings-card">
        <div class="aebg-card-header">
            <h2>🏬 Merchant Exclusions</h2>
        </div>
        <div class="aebg-card-content">
            <div class="aebg-form-group">
                <label for="aebg_excluded_merchants">Merchants to Exclude from Search Results</label>
                <p class="aebg-help-text">
                    <span class="aebg-icon">ℹ️</span>
                    Enter one merchant name per line. Any Datafeedr product whose merchant name matches one of these
                    entries (case-insensitive) will be removed from all search results returned by this plugin.
                </p>
                <textarea
                    id="aebg_excluded_merchants"
                    name="aebg_settings[excluded_merchants]"
                    class="aebg-textarea"
                    rows="8"
                    placeholder="Example:
Ultrashop
Homeshop
Boligcenter.dk"
                ><?php echo esc_textarea( implode( "\n", $excluded_merchants ) ); ?></textarea>
            </div>
        </div>
    </div>
</div>

