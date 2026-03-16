<?php
/**
 * Preview Email Template Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Email Template Preview', 'aebg' ); ?> - <?php echo esc_html( $template->template_name ); ?></title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
			background: #f5f5f5;
			padding: 20px;
			margin: 0;
		}
		.preview-container {
			max-width: 800px;
			margin: 0 auto;
			background: white;
			border-radius: 8px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
			overflow: hidden;
		}
		.preview-header {
			background: #667eea;
			color: white;
			padding: 20px;
		}
		.preview-header h1 {
			margin: 0 0 8px 0;
			font-size: 24px;
		}
		.preview-header p {
			margin: 0;
			opacity: 0.9;
			font-size: 14px;
		}
		.preview-subject {
			background: #f8f9fa;
			padding: 15px 20px;
			border-bottom: 1px solid #e5e7eb;
		}
		.preview-subject strong {
			display: block;
			margin-bottom: 8px;
			color: #374151;
			font-size: 13px;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		.preview-subject .subject-text {
			font-size: 16px;
			color: #1f2937;
		}
		.preview-content {
			padding: 30px;
			min-height: 400px;
		}
		.preview-actions {
			padding: 20px;
			background: #f8f9fa;
			border-top: 1px solid #e5e7eb;
			text-align: center;
		}
		.preview-actions .button {
			margin: 0 8px;
		}
	</style>
</head>
<body>
	<div class="preview-container">
		<div class="preview-header">
			<h1><?php echo esc_html( $template->template_name ); ?></h1>
			<p><?php esc_html_e( 'Email Template Preview', 'aebg' ); ?></p>
		</div>
		
		<div class="preview-subject">
			<strong><?php esc_html_e( 'Subject:', 'aebg' ); ?></strong>
			<div class="subject-text"><?php echo esc_html( $processed['subject'] ); ?></div>
		</div>
		
		<div class="preview-content">
			<?php echo wp_kses_post( $processed['content_html'] ); ?>
		</div>
		
		<div class="preview-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-templates&action=edit&id=' . $template->id ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Edit Template', 'aebg' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-templates' ) ); ?>" class="button">
				<?php esc_html_e( 'Back to Templates', 'aebg' ); ?>
			</a>
			<button type="button" class="button" onclick="window.print();">
				<?php esc_html_e( 'Print', 'aebg' ); ?>
			</button>
		</div>
	</div>
</body>
</html>
<?php exit; ?>

