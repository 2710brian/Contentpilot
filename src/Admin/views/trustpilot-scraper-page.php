<?php
/**
 * Trustpilot Scraper Page View
 * 
 * Simple page to test Trustpilot rating scraping by entering a URL
 *
 * @package AEBG\Admin\Views
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Initialize TrustpilotScraper
$scraper = new \AEBG\Core\TrustpilotScraper();

// Handle form submission
$result = null;
$error = null;
$input_url = '';
$extracted_domain = '';

if (isset($_POST['aebg_scrape_url']) && !empty($_POST['aebg_scrape_url'])) {
    $input_url = sanitize_text_field($_POST['aebg_scrape_url']);
    
    // Extract domain from URL
    $parsed = parse_url($input_url);
    if (!isset($parsed['host'])) {
        // If no host, try to extract domain from the string
        $input_url = preg_replace('/^https?:\/\//', '', $input_url);
        $input_url = preg_replace('/^www\./', '', $input_url);
        $parts = explode('/', $input_url);
        $extracted_domain = $parts[0];
    } else {
        $extracted_domain = $parsed['host'];
        // Remove www. prefix
        $extracted_domain = preg_replace('/^www\./', '', $extracted_domain);
    }
    
    // Remove port if present
    $extracted_domain = preg_replace('/:[0-9]+$/', '', $extracted_domain);
    
    if (!empty($extracted_domain)) {
        // Build full URL for scraping (add https:// if not present)
        $full_url = $input_url;
        if (!preg_match('/^https?:\/\//', $full_url)) {
            $full_url = 'https://' . $full_url;
        }
        
        // Get rating (this will scrape, not use cache)
        $result = $scraper->get_merchant_rating($extracted_domain, $full_url);
        
        if (!$result) {
            $error = 'Could not find Trustpilot rating for this domain. The merchant may not have a Trustpilot page, or the page structure may have changed.';
        }
    } else {
        $error = 'Could not extract domain from the provided URL.';
    }
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Trustpilot Scraper</h1>
    <p class="description">Enter a merchant URL to find and display their Trustpilot rating. The scraper will automatically extract the domain and look up the rating.</p>
    
    <div class="aebg-scraper-container" style="max-width: 800px; margin-top: 20px;">
        <form method="post" action="" id="aebg-scraper-form">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="aebg_scrape_url">Merchant URL</label>
                    </th>
                    <td>
                        <input 
                            type="text" 
                            id="aebg_scrape_url" 
                            name="aebg_scrape_url" 
                            value="<?php echo esc_attr($input_url); ?>" 
                            placeholder="e.g., amazon.com/products/item123 or https://www.ebay.com"
                            class="regular-text"
                            style="width: 100%; max-width: 500px;"
                        />
                        <p class="description">Enter any URL from the merchant's website. The scraper will extract the domain automatically.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="Get Trustpilot Rating" />
            </p>
        </form>
        
        <?php if (!empty($extracted_domain)): ?>
            <div class="notice notice-info" style="margin-top: 20px;">
                <p><strong>Extracted Domain:</strong> <code><?php echo esc_html($extracted_domain); ?></code></p>
                <p><strong>Trustpilot URL:</strong> <a href="https://www.trustpilot.com/review/<?php echo esc_attr($extracted_domain); ?>" target="_blank">https://www.trustpilot.com/review/<?php echo esc_html($extracted_domain); ?></a></p>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="notice notice-error" style="margin-top: 20px;">
                <p><strong>Error:</strong> <?php echo esc_html($error); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($result && is_array($result)): ?>
            <div class="aebg-scraper-result" style="margin-top: 20px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <h2 style="margin-top: 0;">Trustpilot Rating</h2>
                
                <div style="display: flex; align-items: center; gap: 20px; margin: 20px 0;">
                    <div style="font-size: 48px; font-weight: bold; color: #00b67a;">
                        <?php echo esc_html(number_format($result['rating'], 1)); ?><span style="font-size: 24px; color: #666;">/5</span>
                    </div>
                    <div>
                        <div style="font-size: 18px; margin-bottom: 5px;">
                            <?php
                            // Generate stars
                            $rating = floatval($result['rating']);
                            $full_stars = floor($rating);
                            $half_star = ($rating - $full_stars) >= 0.5;
                            $empty_stars = 5 - $full_stars - ($half_star ? 1 : 0);
                            
                            // Full stars
                            for ($i = 0; $i < $full_stars; $i++) {
                                echo '<span style="color: #00b67a; font-size: 24px;">★</span>';
                            }
                            // Half star
                            if ($half_star) {
                                echo '<span style="color: #00b67a; font-size: 24px;">☆</span>';
                            }
                            // Empty stars
                            for ($i = 0; $i < $empty_stars; $i++) {
                                echo '<span style="color: #ddd; font-size: 24px;">★</span>';
                            }
                            ?>
                        </div>
                        <?php if (!empty($result['review_count'])): ?>
                            <div style="color: #666; font-size: 14px;">
                                <?php echo number_format($result['review_count']); ?> reviews
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                    <p style="margin: 0; color: #666; font-size: 13px;">
                        <strong>Domain:</strong> <?php echo esc_html($extracted_domain); ?><br>
                        <?php if (!empty($result['fetched_at'])): ?>
                            <strong>Fetched:</strong> <?php echo esc_html($result['fetched_at']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div style="margin-top: 15px;">
                    <a href="https://www.trustpilot.com/review/<?php echo esc_attr($extracted_domain); ?>" target="_blank" class="button">
                        View on Trustpilot →
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="aebg-scraper-info" style="margin-top: 30px; padding: 15px; background: #f0f9ff; border-left: 4px solid #0ea5e9; max-width: 800px;">
        <h3 style="margin-top: 0;">How it works</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li>Enter any URL from a merchant's website (e.g., <code>amazon.com/products/item123</code>)</li>
            <li>The scraper automatically extracts the domain (e.g., <code>amazon.com</code>)</li>
            <li>It looks up the merchant on Trustpilot using the format: <code>trustpilot.com/review/{domain}</code></li>
            <li>The rating and review count are displayed (if found)</li>
            <li><strong>Note:</strong> This is a test page and does not save results to the database</li>
        </ul>
    </div>
</div>

<style>
.aebg-scraper-container {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.aebg-scraper-result {
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

