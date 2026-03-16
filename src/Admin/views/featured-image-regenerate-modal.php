<?php
/**
 * Featured Image Regeneration Modal
 *
 * @package AEBG\Admin\Views
 */

// Get available styles from settings
$settings = get_option( 'aebg_settings', [] );
$default_style = $settings['featured_image_style'] ?? 'realistic photo';

$styles = [
	'realistic photo' => '📷 Realistic Photo',
	'digital art'     => '🎨 Digital Art',
	'illustration'    => '✏️ Illustration',
	'3D render'       => '🎬 3D Render',
	'minimalist'      => '⚪ Minimalist',
	'vintage'         => '📜 Vintage',
	'modern'          => '🚀 Modern',
	'professional'   => '💼 Professional',
];
?>

<!-- Featured Image Regeneration Modal -->
<div id="aebg-featured-image-modal" class="aebg-modal" style="display: none;">
	<div class="aebg-modal-content">
		<div class="aebg-modal-header">
			<h3>🎨 Regenerate Featured Image</h3>
			<button type="button" class="aebg-modal-close">&times;</button>
		</div>
		<div class="aebg-modal-body">
			<!-- Current image preview (if exists) -->
			<div class="aebg-current-image-preview" id="aebg-current-image-preview" style="display: none;">
				<h4>Current Featured Image</h4>
				<div id="aebg-current-image-container"></div>
			</div>

			<!-- Style selector -->
			<div class="aebg-form-group">
				<label for="aebg-featured-image-style">Visual Style</label>
				<select id="aebg-featured-image-style" class="aebg-select">
					<?php foreach ( $styles as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $default_style ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Image Model Selection (Reuse from Generator V2) -->
			<div class="aebg-form-group">
				<label for="aebg-featured-image-model">Image Model</label>
				<select id="aebg-featured-image-model" class="aebg-select">
					<option value="dall-e-3" selected>DALL-E 3 (Recommended - Best Quality)</option>
					<option value="dall-e-2">DALL-E 2 (Legacy - Lower Cost)</option>
					<optgroup label="Nano Banana (Google Gemini)">
						<option value="nano-banana-2">Nano Banana 2 (Gemini 3.1 Flash – recommended)</option>
						<option value="nano-banana">Nano Banana (Gemini 2.5 Flash – fast)</option>
						<option value="nano-banana-pro">Nano Banana Pro (Gemini 3 Pro – professional)</option>
					</optgroup>
				</select>
			</div>

			<!-- Image Size Selection (Reuse from Generator V2) -->
			<div class="aebg-form-group">
				<label for="aebg-featured-image-size">Image Size</label>
				<select id="aebg-featured-image-size" class="aebg-select">
					<option value="1024x1024" selected>Square (1024×1024)</option>
					<option value="1792x1024">Landscape (1792×1024)</option>
					<option value="1024x1792">Portrait (1024×1792)</option>
				</select>
			</div>

			<!-- Image Quality Selection (Reuse from Generator V2) -->
			<div class="aebg-form-group">
				<label>Image Quality</label>
				<div class="aebg-radio-group aebg-radio-group-horizontal">
					<label class="aebg-radio-option">
						<input type="radio" name="aebg-featured-image-quality" value="standard" checked>
						<span class="aebg-radio-content">
							<strong>Standard Quality</strong>
							<small>Faster generation, lower cost ($0.04 per image)</small>
						</span>
					</label>
					<label class="aebg-radio-option">
						<input type="radio" name="aebg-featured-image-quality" value="hd">
						<span class="aebg-radio-content">
							<strong>HD Quality</strong>
							<small>Higher resolution, slower generation, higher cost ($0.08 per image)</small>
						</span>
					</label>
				</div>
			</div>

			<!-- Cost & Time Estimate (Reuse from Generator V2) -->
			<div class="aebg-featured-image-estimate" id="aebg-featured-image-estimate">
				<div class="aebg-estimate-header">
					<h4>💰 Cost & Time Estimate</h4>
				</div>
				<div class="aebg-estimate-content">
					<div class="aebg-estimate-item">
						<span>Estimated Cost:</span>
						<strong id="aebg-featured-image-cost">$0.04</strong>
					</div>
					<div class="aebg-estimate-item">
						<span>Estimated Time:</span>
						<strong id="aebg-featured-image-time">~15 seconds</strong>
					</div>
				</div>
				<p class="aebg-estimate-note">
					<span class="aebg-icon">ℹ️</span>
					Estimates update automatically based on your selections.
				</p>
			</div>

			<!-- Custom Prompt Toggle -->
			<div class="aebg-form-group aebg-custom-prompt-toggle">
				<label>
					<input type="checkbox" id="aebg-use-custom-prompt">
					Use custom prompt (Advanced)
				</label>
				<p class="description">Override auto-generated prompt with your own</p>
			</div>

			<!-- Custom Prompt Field (hidden by default) -->
			<div class="aebg-form-group" id="aebg-custom-prompt-group" style="display: none;">
				<label for="aebg-custom-prompt">Custom Prompt</label>
				<textarea
					id="aebg-custom-prompt"
					class="aebg-textarea"
					rows="4"
					placeholder="Describe the image you want to generate..."
					maxlength="1000"></textarea>
				<div class="aebg-char-counter">
					<span id="aebg-prompt-char-count">0</span> / 1000 characters
				</div>
				<p class="description">
					💡 Tip: Be specific about composition, colors, mood, and key elements.
					The style you selected will still be applied.
				</p>
			</div>

			<!-- Progress indicator (hidden initially) -->
			<div class="aebg-featured-image-progress" id="aebg-featured-image-progress" style="display: none;">
				<!-- Progress will be dynamically inserted here -->
			</div>

			<!-- Preview area (hidden initially) -->
			<div class="aebg-featured-image-preview" id="aebg-featured-image-preview" style="display: none;">
				<!-- Generated image preview will be inserted here -->
			</div>
		</div>
		<div class="aebg-modal-footer">
			<button type="button" class="aebg-btn aebg-btn-secondary aebg-modal-close">Cancel</button>
			<button type="button" class="aebg-btn aebg-btn-primary" id="aebg-generate-featured-image">
				<span class="aebg-btn-text">Generate Image</span>
				<span class="aebg-btn-spinner" style="display: none;">⏳</span>
			</button>
		</div>
	</div>
</div>

