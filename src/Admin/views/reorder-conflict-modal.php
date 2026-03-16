<?php
/**
 * Reorder Conflict Modal Template
 * 
 * Displays conflicts when products are reordered to positions
 * that have testvinder containers, allowing users to choose
 * how to handle each conflict.
 * 
 * @package AEBG\Admin\Views
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}
?>

<div id="aebg-reorder-conflict-modal" class="aebg-modal">
	<div class="aebg-modal-overlay"></div>
	<div class="aebg-modal-container">
		<div class="aebg-modal-header">
			<h3><?php esc_html_e('Product Reordering Conflicts', 'aebg'); ?></h3>
			<button class="aebg-modal-close" aria-label="<?php esc_attr_e('Close', 'aebg'); ?>">&times;</button>
		</div>
		<div class="aebg-modal-body">
			<p class="aebg-conflict-intro">
				<?php esc_html_e('The following products are moving to positions that have testvinder containers. Choose how to handle each:', 'aebg'); ?>
			</p>
			<div class="aebg-conflicts-list" id="aebg-conflicts-list">
				<!-- Populated by JavaScript -->
			</div>
		</div>
		<div class="aebg-modal-footer">
			<button type="button" class="button aebg-btn-cancel">
				<?php esc_html_e('Cancel', 'aebg'); ?>
			</button>
			<button type="button" class="button button-primary aebg-btn-proceed">
				<?php esc_html_e('Proceed with Reordering', 'aebg'); ?>
			</button>
		</div>
	</div>
</div>

