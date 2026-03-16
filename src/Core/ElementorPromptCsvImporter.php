<?php

namespace AEBG\Core;

/**
 * CSV importer that creates Elementor templates and assigns per-widget AI prompts.
 *
 * CSV headers (case-insensitive):
 * - template_name (required)
 * - template_type (optional: page|section, default: page)
 * - widget_type (required: heading|text-editor|button|image|text)
 * - prompt (required)
 * - content (optional fallback content)
 * - order (optional numeric; controls widget order)
 *
 * Also supports "wide article CSV" format (like your app export) where each row is an article and
 * columns contain prompt text. In that mode:
 * - Title is used as template name
 * - Each non-empty column (except a small ignore list) becomes a widget (text-editor by default)
 */
class ElementorPromptCsvImporter {
	/**
	 * Import templates from a CSV file path.
	 *
	 * @param string $csv_file_path Absolute/temporary file path.
	 * @return array|\WP_Error
	 */
	public function import_from_csv_file( string $csv_file_path ) {
		if ( ! file_exists( $csv_file_path ) || ! is_readable( $csv_file_path ) ) {
			return new \WP_Error( 'aebg_csv_unreadable', __( 'CSV file could not be read.', 'aebg' ) );
		}

		if ( ! did_action( 'elementor/loaded' ) ) {
			return new \WP_Error( 'aebg_elementor_missing', __( 'Elementor does not appear to be loaded. Activate Elementor and try again.', 'aebg' ) );
		}

		$handle = fopen( $csv_file_path, 'r' );
		if ( ! $handle ) {
			return new \WP_Error( 'aebg_csv_open_failed', __( 'Failed to open CSV file.', 'aebg' ) );
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return new \WP_Error( 'aebg_csv_no_header', __( 'CSV is missing a header row.', 'aebg' ) );
		}

		$map = $this->normalize_header_map( $header );
		$has_compact_format = isset( $map['template_name'], $map['widget_type'], $map['prompt'] );
		$has_wide_format    = isset( $map['title'] );

		$rows_by_template = [];
		$row_num          = 1;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$row_num++;
			if ( ! is_array( $row ) || count( array_filter( $row, fn( $v ) => (string) $v !== '' ) ) === 0 ) {
				continue;
			}

			if ( $has_compact_format ) {
				$template_name = sanitize_text_field( $this->get_col( $row, $map, 'template_name' ) );
				$widget_type   = sanitize_text_field( $this->get_col( $row, $map, 'widget_type' ) );
				$prompt        = sanitize_textarea_field( $this->get_col( $row, $map, 'prompt' ) );

				if ( $template_name === '' ) {
					fclose( $handle );
					return new \WP_Error( 'aebg_csv_invalid_row', sprintf( __( 'Row %d is missing template_name.', 'aebg' ), $row_num ) );
				}
				if ( $widget_type === '' ) {
					fclose( $handle );
					return new \WP_Error( 'aebg_csv_invalid_row', sprintf( __( 'Row %d is missing widget_type.', 'aebg' ), $row_num ) );
				}
				if ( $prompt === '' ) {
					fclose( $handle );
					return new \WP_Error( 'aebg_csv_invalid_row', sprintf( __( 'Row %d is missing prompt.', 'aebg' ), $row_num ) );
				}

				$template_type = $this->get_col( $row, $map, 'template_type' );
				$template_type = $template_type !== '' ? sanitize_text_field( $template_type ) : 'page';
				$template_type = in_array( $template_type, [ 'page', 'section' ], true ) ? $template_type : 'page';

				$content = $this->get_col( $row, $map, 'content' );
				$content = $content !== '' ? sanitize_textarea_field( $content ) : '';

				$order = $this->get_col( $row, $map, 'order' );
				$order = is_numeric( $order ) ? (int) $order : 0;

				$rows_by_template[ $template_name ][] = [
					'template_type' => $template_type,
					'widget_type'   => $widget_type,
					'prompt'        => $prompt,
					'content'       => $content,
					'order'         => $order,
					'row_num'       => $row_num,
				];
				continue;
			}

			// Wide article CSV: use Title as template name; each column becomes a widget.
			if ( $has_wide_format ) {
				$template_name = sanitize_text_field( $this->get_col( $row, $map, 'title' ) );
				if ( $template_name === '' ) {
					// Skip empty row (no title)
					continue;
				}

				$template_type = 'page';

				// Add a heading widget first (prompt from Title could be empty; we treat Title as fallback content).
				$rows_by_template[ $template_name ][] = [
					'template_type' => $template_type,
					'widget_type'   => 'heading',
					'prompt'        => sanitize_textarea_field( $template_name ),
					'content'       => $template_name,
					'order'         => 0,
					'row_num'       => $row_num,
				];

				$ignore = [
					'categories',
					'title',
					'seo_title',
					'focus_keyword',
				];

				$order = 1;
				foreach ( $map as $col_key => $idx ) {
					if ( in_array( $col_key, $ignore, true ) ) {
						continue;
					}

					$cell = isset( $row[ $idx ] ) ? (string) $row[ $idx ] : '';
					$cell = trim( $cell );
					if ( $cell === '' ) {
						continue;
					}

					$rows_by_template[ $template_name ][] = [
						'template_type' => $template_type,
						'widget_type'   => 'text-editor',
						'prompt'        => sanitize_textarea_field( $cell ),
						'content'       => '', // keep preview minimal; prompt is the main payload
						'order'         => $order,
						'row_num'       => $row_num,
					];
					$order++;
				}
				continue;
			}
		}
		fclose( $handle );

		if ( ! $has_compact_format && ! $has_wide_format ) {
			return new \WP_Error(
				'aebg_csv_unknown_format',
				__( 'CSV format not recognized. Expected either (template_name, widget_type, prompt) columns or a wide format with a Title column.', 'aebg' )
			);
		}

		if ( empty( $rows_by_template ) ) {
			return new \WP_Error( 'aebg_csv_empty', __( 'CSV contained no importable rows.', 'aebg' ) );
		}

		$created = [];
		foreach ( $rows_by_template as $template_name => $rows ) {
			usort(
				$rows,
				function ( $a, $b ) {
					return ( $a['order'] <=> $b['order'] ) ?: ( $a['row_num'] <=> $b['row_num'] );
				}
			);

			$template_type = $rows[0]['template_type'] ?? 'page';
			$elementor_data = $this->build_elementor_data_from_rows( $rows );

			$template_id = $this->create_elementor_library_template( $template_name, $template_type, $elementor_data );
			if ( is_wp_error( $template_id ) ) {
				return $template_id;
			}

			$created[] = [
				'template_id'   => (int) $template_id,
				'template_name' => $template_name,
				'template_type' => $template_type,
				'widget_count'  => count( $rows ),
			];
		}

		return [
			'created' => $created,
		];
	}

	private function normalize_header_map( array $header ): array {
		$map = [];
		foreach ( $header as $idx => $name ) {
			$key = strtolower( trim( (string) $name ) );
			$key = str_replace( [ ' ', '-' ], '_', $key );
			if ( $key !== '' ) {
				$map[ $key ] = $idx;
			}
		}
		return $map;
	}

	private function get_col( array $row, array $map, string $key ): string {
		if ( ! isset( $map[ $key ] ) ) {
			return '';
		}
		$idx = $map[ $key ];
		return isset( $row[ $idx ] ) ? (string) $row[ $idx ] : '';
	}

	private function create_elementor_library_template( string $name, string $template_type, array $elementor_data ) {
		$post_id = wp_insert_post(
			[
				'post_type'   => 'elementor_library',
				'post_status' => 'publish',
				'post_title'  => $name,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', $template_type );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		$json = wp_json_encode( $elementor_data, JSON_UNESCAPED_UNICODE );
		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

		return $post_id;
	}

	private function build_elementor_data_from_rows( array $rows ): array {
		$widgets = [];
		foreach ( $rows as $row ) {
			$widgets[] = $this->build_widget( $row['widget_type'], $row['prompt'], $row['content'] );
		}

		$container = [
			'id'       => $this->new_element_id(),
			'elType'   => 'container',
			'settings' => [],
			'elements' => $widgets,
			'isInner'  => false,
		];

		// Minimal top-level element structure Elementor accepts (container as root element)
		return [ $container ];
	}

	private function build_widget( string $widget_type, string $prompt, string $fallback_content ): array {
		$widget_type = strtolower( trim( $widget_type ) );

		$settings = [
			'aebg_ai_enable' => 'yes',
			'aebg_ai_prompt' => $prompt,
		];

		// Add widget-specific fallback content so template still previews OK in Elementor.
		switch ( $widget_type ) {
			case 'heading':
				$settings['title'] = $fallback_content !== '' ? $fallback_content : __( 'Heading', 'aebg' );
				break;
			case 'text-editor':
				$settings['editor'] = $fallback_content !== '' ? $fallback_content : __( 'Text', 'aebg' );
				break;
			case 'button':
				$settings['text'] = $fallback_content !== '' ? $fallback_content : __( 'Click', 'aebg' );
				break;
			case 'text':
				$settings['text'] = $fallback_content !== '' ? $fallback_content : __( 'Text', 'aebg' );
				break;
			case 'image':
				// Leave empty; AI will generate/replace during bulk generation. Preview can be set later.
				break;
			default:
				// Fallback to text-editor if unknown
				$widget_type         = 'text-editor';
				$settings['editor']  = $fallback_content !== '' ? $fallback_content : __( 'Text', 'aebg' );
				break;
		}

		return [
			'id'         => $this->new_element_id(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => [],
			'isInner'    => false,
		];
	}

	private function new_element_id(): string {
		$uuid = wp_generate_uuid4();
		return substr( str_replace( '-', '', $uuid ), 0, 7 );
	}
}

