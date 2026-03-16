<?php
/**
 * CSV Prompt Import Page (Elementor templates)
 *
 * @package AEBG\Admin\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$result_key = isset( $_GET['aebg_result'] ) ? sanitize_text_field( wp_unslash( $_GET['aebg_result'] ) ) : '';
$status     = isset( $_GET['aebg_import'] ) ? sanitize_text_field( wp_unslash( $_GET['aebg_import'] ) ) : '';
$result     = $result_key ? get_transient( $result_key ) : null;
?>

<div class="wrap">
	<h1><?php esc_html_e( 'CSV Prompt Import → Elementor Template', 'aebg' ); ?></h1>

	<?php if ( $status === 'no_file' ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'No CSV file was uploaded.', 'aebg' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $status === 'error' && is_array( $result ) && ! empty( $result['error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( $status === 'success' && is_array( $result ) && ! empty( $result['created'] ) ) : ?>
		<div class="notice notice-success">
			<p><?php esc_html_e( 'Import completed. Created Elementor templates:', 'aebg' ); ?></p>
			<ul>
				<?php foreach ( $result['created'] as $created ) : ?>
					<li>
						<strong><?php echo esc_html( $created['template_name'] ); ?></strong>
						(<?php echo esc_html( $created['template_type'] ); ?>) –
						<?php echo esc_html( sprintf( __( '%d widgets', 'aebg' ), (int) $created['widget_count'] ) ); ?>
						– <a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $created['template_id'] . '&action=elementor' ) ); ?>">
							<?php esc_html_e( 'Open in Elementor', 'aebg' ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="card" style="max-width: 900px;">
		<h2><?php esc_html_e( 'Upload CSV', 'aebg' ); ?></h2>
		<p><?php esc_html_e( 'This will create one or more new Elementor templates and assign per-widget AI prompts (aebg_ai_prompt).', 'aebg' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="aebg_import_elementor_prompt_csv" />
			<?php wp_nonce_field( 'aebg_import_elementor_prompt_csv', 'aebg_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="aebg_prompt_csv"><?php esc_html_e( 'CSV file', 'aebg' ); ?></label>
					</th>
					<td>
						<input type="file" id="aebg_prompt_csv" name="aebg_prompt_csv" accept=".csv,text/csv" required />
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Import and Create Template(s)', 'aebg' ) ); ?>
		</form>
	</div>

	<div class="card" style="max-width: 900px;">
		<h2><?php esc_html_e( 'CSV format', 'aebg' ); ?></h2>
		<p><strong><?php esc_html_e( 'Supported formats:', 'aebg' ); ?></strong></p>
		<ul>
			<li><?php esc_html_e( 'Compact format (recommended): template_name, widget_type, prompt (+ optional template_type, content, order)', 'aebg' ); ?></li>
			<li><?php esc_html_e( 'Wide article format (your app export): requires a Title column. Each non-empty cell becomes a widget prompt in the new template.', 'aebg' ); ?></li>
		</ul>

<pre>template_name,template_type,widget_type,order,prompt,content
"Min AI Template",page,heading,1,"Skriv en H1 baseret på {title}","Overskrift"
"Min AI Template",page,text-editor,2,"Skriv introduktion baseret på {title} og {product-1}",""
"Min AI Template",page,button,3,"Lav CTA knaptekst til {product-1}","Køb nu"</pre>

		<p><?php esc_html_e( 'Wide format example header (no need for widget_type/prompt columns):', 'aebg' ); ?></p>
<pre>Categories,Title,SEO Title,Focus Keyword,Excerpt,List Introduktion,Content,Konklusion,FAQ</pre>
	</div>
</div>

