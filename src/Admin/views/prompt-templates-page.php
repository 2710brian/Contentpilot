<?php
/**
 * Prompt Templates Admin Page
 *
 * @package AEBG\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap aebg-prompt-templates-page">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'AI Prompt Templates', 'aebg' ); ?>
	</h1>
	<button type="button" class="page-title-action" id="aebg-create-template-btn">
		<?php esc_html_e( 'Add New Template', 'aebg' ); ?>
	</button>
	<hr class="wp-header-end">

	<div class="aebg-templates-container">
		<!-- Filters and Search -->
		<div class="aebg-templates-filters">
			<div class="aebg-filter-group">
				<input type="search" id="aebg-template-search" class="aebg-search-input" 
				       placeholder="<?php esc_attr_e( 'Search templates...', 'aebg' ); ?>">
			</div>
			<div class="aebg-filter-group">
				<select id="aebg-template-category-filter" class="aebg-select">
					<option value=""><?php esc_html_e( 'All Categories', 'aebg' ); ?></option>
				</select>
			</div>
			<div class="aebg-filter-group">
				<select id="aebg-template-sort" class="aebg-select">
					<option value="created_at-desc"><?php esc_html_e( 'Newest First', 'aebg' ); ?></option>
					<option value="created_at-asc"><?php esc_html_e( 'Oldest First', 'aebg' ); ?></option>
					<option value="name-asc"><?php esc_html_e( 'Name (A-Z)', 'aebg' ); ?></option>
					<option value="name-desc"><?php esc_html_e( 'Name (Z-A)', 'aebg' ); ?></option>
					<option value="usage_count-desc"><?php esc_html_e( 'Most Used', 'aebg' ); ?></option>
					<option value="usage_count-asc"><?php esc_html_e( 'Least Used', 'aebg' ); ?></option>
				</select>
			</div>
			<div class="aebg-filter-group">
				<label>
					<input type="checkbox" id="aebg-show-public-only" value="1">
					<?php esc_html_e( 'Show Public Templates', 'aebg' ); ?>
				</label>
			</div>
		</div>

		<!-- Templates Grid -->
		<div id="aebg-templates-grid" class="aebg-templates-grid">
			<div class="aebg-loading">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Loading templates...', 'aebg' ); ?>
			</div>
		</div>

		<!-- Pagination -->
		<div class="aebg-templates-pagination" id="aebg-templates-pagination"></div>
	</div>
</div>

<!-- Template Modal -->
<div id="aebg-template-modal" class="aebg-modal" style="display: none;">
	<div class="aebg-modal-content">
		<div class="aebg-modal-header">
			<h2 id="aebg-modal-title"><?php esc_html_e( 'Create Template', 'aebg' ); ?></h2>
			<button type="button" class="aebg-modal-close" aria-label="<?php esc_attr_e( 'Close', 'aebg' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<div class="aebg-modal-body">
			<form id="aebg-template-form">
				<input type="hidden" id="aebg-template-id" name="id" value="">
				
				<div class="aebg-form-group">
					<label for="aebg-template-name">
						<?php esc_html_e( 'Template Name', 'aebg' ); ?>
						<span class="required">*</span>
					</label>
					<input type="text" id="aebg-template-name" name="name" class="aebg-input" required>
				</div>

				<div class="aebg-form-group">
					<label for="aebg-template-description">
						<?php esc_html_e( 'Description', 'aebg' ); ?>
					</label>
					<textarea id="aebg-template-description" name="description" class="aebg-textarea" rows="3"></textarea>
				</div>

				<div class="aebg-form-group">
					<label for="aebg-template-prompt">
						<?php esc_html_e( 'Prompt Text', 'aebg' ); ?>
						<span class="required">*</span>
					</label>
					<textarea id="aebg-template-prompt" name="prompt" class="aebg-textarea" rows="8" required></textarea>
					<p class="description">
						<?php esc_html_e( 'Enter the AI prompt text. You can use variables like {product-name}, {title}, etc.', 'aebg' ); ?>
					</p>
				</div>

				<div class="aebg-form-row">
					<div class="aebg-form-group">
						<label for="aebg-template-category">
							<?php esc_html_e( 'Category', 'aebg' ); ?>
						</label>
						<select id="aebg-template-category" name="category" class="aebg-select">
							<option value="general"><?php esc_html_e( 'General', 'aebg' ); ?></option>
							<option value="product-descriptions"><?php esc_html_e( 'Product Descriptions', 'aebg' ); ?></option>
							<option value="headlines"><?php esc_html_e( 'Headlines', 'aebg' ); ?></option>
							<option value="images"><?php esc_html_e( 'Images', 'aebg' ); ?></option>
							<option value="buttons"><?php esc_html_e( 'Buttons', 'aebg' ); ?></option>
							<option value="text"><?php esc_html_e( 'Text Content', 'aebg' ); ?></option>
						</select>
						<input type="text" id="aebg-template-category-custom" class="aebg-input" 
						       placeholder="<?php esc_attr_e( 'Or enter custom category', 'aebg' ); ?>" 
						       style="display: none; margin-top: 5px;">
					</div>

					<div class="aebg-form-group">
						<label>
							<input type="checkbox" id="aebg-template-is-public" name="is_public" value="1">
							<?php esc_html_e( 'Make this template public', 'aebg' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Public templates can be used by all users.', 'aebg' ); ?>
						</p>
					</div>
				</div>

				<div class="aebg-form-group">
					<label for="aebg-template-tags">
						<?php esc_html_e( 'Tags', 'aebg' ); ?>
					</label>
					<input type="text" id="aebg-template-tags" name="tags" class="aebg-input" 
					       placeholder="<?php esc_attr_e( 'Comma-separated tags', 'aebg' ); ?>">
					<p class="description">
						<?php esc_html_e( 'Add tags to help organize your templates.', 'aebg' ); ?>
					</p>
				</div>

				<div class="aebg-form-group">
					<label for="aebg-template-widget-types">
						<?php esc_html_e( 'Compatible Widget Types', 'aebg' ); ?>
					</label>
					<select id="aebg-template-widget-types" name="widget_types[]" class="aebg-select" multiple>
						<option value="heading"><?php esc_html_e( 'Heading', 'aebg' ); ?></option>
						<option value="text-editor"><?php esc_html_e( 'Text Editor', 'aebg' ); ?></option>
						<option value="text"><?php esc_html_e( 'Text', 'aebg' ); ?></option>
						<option value="image"><?php esc_html_e( 'Image', 'aebg' ); ?></option>
						<option value="button"><?php esc_html_e( 'Button', 'aebg' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select widget types this template is compatible with. Leave empty for all widgets.', 'aebg' ); ?>
					</p>
				</div>
			</form>
		</div>
		<div class="aebg-modal-footer">
			<button type="button" class="button" id="aebg-template-cancel-btn">
				<?php esc_html_e( 'Cancel', 'aebg' ); ?>
			</button>
			<button type="button" class="button button-primary" id="aebg-template-save-btn">
				<?php esc_html_e( 'Save Template', 'aebg' ); ?>
			</button>
		</div>
	</div>
</div>

<!-- Template Card Template (for JavaScript) -->
<script type="text/template" id="aebg-template-card-template">
	<div class="aebg-template-card" data-template-id="{{id}}">
		<div class="aebg-template-card-header">
			<h3 class="aebg-template-card-title">{{name}}</h3>
			<div class="aebg-template-card-actions">
				<button type="button" class="aebg-btn-icon aebg-btn-edit" data-action="edit" title="<?php esc_attr_e( 'Edit', 'aebg' ); ?>">
					<span class="dashicons dashicons-edit"></span>
				</button>
				<button type="button" class="aebg-btn-icon aebg-btn-duplicate" data-action="duplicate" title="<?php esc_attr_e( 'Duplicate', 'aebg' ); ?>">
					<span class="dashicons dashicons-admin-page"></span>
				</button>
				<button type="button" class="aebg-btn-icon aebg-btn-delete" data-action="delete" title="<?php esc_attr_e( 'Delete', 'aebg' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</div>
		</div>
		<div class="aebg-template-card-body">
			{{#if description}}
			<p class="aebg-template-card-description">{{description}}</p>
			{{/if}}
			<div class="aebg-template-card-preview">
				<pre>{{prompt_preview}}</pre>
			</div>
		</div>
		<div class="aebg-template-card-footer">
			<div class="aebg-template-card-meta">
				<span class="aebg-template-category">{{category}}</span>
				{{#if is_public}}
				<span class="aebg-template-badge aebg-template-public"><?php esc_html_e( 'Public', 'aebg' ); ?></span>
				{{/if}}
			</div>
			<div class="aebg-template-card-stats">
				<span class="aebg-template-usage">
					<span class="dashicons dashicons-chart-line"></span>
					{{usage_count}}
				</span>
				{{#if last_used_at}}
				<span class="aebg-template-last-used" title="{{last_used_at}}">
					<?php esc_html_e( 'Used', 'aebg' ); ?> {{last_used_relative}}
				</span>
				{{/if}}
			</div>
		</div>
	</div>
</script>

