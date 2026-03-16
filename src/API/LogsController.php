<?php

namespace AEBG\API;

/**
 * Logs Controller Class
 *
 * Handles REST API endpoints for logs page
 *
 * @package AEBG\API
 */
class LogsController extends \WP_REST_Controller {
	/**
	 * LogsController constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			'aebg/v1',
			'/logs',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_logs' ],
				'permission_callback' => [ $this, 'get_logs_permissions_check' ],
				'args'                => [
					'page'      => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page'  => [
						'default'           => 50,
						'sanitize_callback' => 'absint',
					],
					'search'    => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'level'     => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type'      => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status'    => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'batch_id'  => [
						'sanitize_callback' => 'absint',
					],
					'user_id'   => [
						'sanitize_callback' => 'absint',
					],
					'date_from' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'export'    => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'format'    => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/logs/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_logs_stats' ],
				'permission_callback' => [ $this, 'get_logs_permissions_check' ],
			]
		);
	}

	/**
	 * Check if a given request has access to get logs.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return bool|\WP_Error
	 */
	public function get_logs_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get logs from all sources.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_logs( $request ) {
		global $wpdb;

		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$search    = $request->get_param( 'search' );
		$level     = $request->get_param( 'level' );
		$type      = $request->get_param( 'type' );
		$status    = $request->get_param( 'status' );
		$batch_id  = $request->get_param( 'batch_id' );
		$user_id   = $request->get_param( 'user_id' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );
		$export    = $request->get_param( 'export' );
		$format    = $request->get_param( 'format' ) ?: 'json';

		$offset = ( $page - 1 ) * $per_page;

		// Collect logs from all sources
		$logs = [];

		// 1. Batch logs
		if ( ! $type || $type === 'batch' ) {
			$batch_logs = $this->get_batch_logs( $search, $status, $batch_id, $user_id, $date_from, $date_to );
			$logs        = array_merge( $logs, $batch_logs );
		}

		// 2. Batch item logs
		if ( ! $type || $type === 'batch_item' ) {
			$item_logs = $this->get_batch_item_logs( $search, $status, $batch_id, $date_from, $date_to );
			$logs       = array_merge( $logs, $item_logs );
		}

		// 3. Action scheduler logs
		if ( ! $type || $type === 'action_scheduler' ) {
			$action_logs = $this->get_action_scheduler_logs( $search, $date_from, $date_to );
			$logs         = array_merge( $logs, $action_logs );
		}

		// Filter by level if specified
		if ( $level ) {
			$logs = array_filter( $logs, function( $log ) use ( $level ) {
				return $log['level'] === $level;
			} );
		}

		// Sort by timestamp (newest first)
		usort( $logs, function( $a, $b ) {
			return strtotime( $b['timestamp'] ) - strtotime( $a['timestamp'] );
		} );

		// Handle export
		if ( $export === '1' ) {
			return $this->export_logs( $logs, $format );
		}

		// Pagination
		$total_logs = count( $logs );
		$logs       = array_slice( $logs, $offset, $per_page );
		$has_more   = ( $offset + $per_page ) < $total_logs;

		$data = [
			'data'     => $logs,
			'total'    => $total_logs,
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => $has_more,
		];

		$response = new \WP_REST_Response( $data, 200 );
		$response->set_headers( [
			'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
		] );

		return $response;
	}

	/**
	 * Get batch logs.
	 *
	 * @param string $search Search query.
	 * @param string $status Status filter.
	 * @param int    $batch_id Batch ID filter.
	 * @param int    $user_id User ID filter.
	 * @param string $date_from Date from.
	 * @param string $date_to Date to.
	 * @return array
	 */
	private function get_batch_logs( $search = '', $status = '', $batch_id = 0, $user_id = 0, $date_from = '', $date_to = '' ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_batches';
		$where      = [ '1=1' ];
		$params     = [];

		if ( $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $batch_id ) {
			$where[]  = 'id = %d';
			$params[] = $batch_id;
		}

		if ( $user_id ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		if ( $date_from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from;
		}

		if ( $date_to ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// Only use prepare() if we have params AND the query contains placeholders
		if ( ! empty( $params ) && ( strpos( $where_clause, '%' ) !== false ) ) {
			$query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT 1000", $params );
		} else {
			$query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT 1000";
		}

		$batches = $wpdb->get_results( $query, ARRAY_A );

		$logs = [];
		foreach ( $batches as $batch ) {
			// Determine log level based on status
			$level = 'info';
			if ( $batch['status'] === 'failed' || $batch['status'] === 'cancelled' ) {
				$level = 'error';
			} elseif ( $batch['status'] === 'completed' ) {
				$level = 'success';
			} elseif ( $batch['status'] === 'in_progress' || $batch['status'] === 'processing' ) {
				$level = 'info';
			}

			$message = sprintf(
				'Batch #%d: %s - %d/%d items processed, %d failed',
				$batch['id'],
				$batch['status'],
				$batch['processed_items'],
				$batch['total_items'],
				$batch['failed_items']
			);

			// Apply search filter
			if ( $search && stripos( $message, $search ) === false && stripos( (string) $batch['id'], $search ) === false ) {
				continue;
			}

			$logs[] = [
				'id'        => 'batch_' . $batch['id'],
				'type'      => 'batch',
				'level'     => $level,
				'message'   => $message,
				'context'   => [
					'batch_id'        => (int) $batch['id'],
					'user_id'         => (int) $batch['user_id'],
					'template_id'      => (int) $batch['template_id'],
					'status'           => $batch['status'],
					'total_items'      => (int) $batch['total_items'],
					'processed_items'  => (int) $batch['processed_items'],
					'failed_items'      => (int) $batch['failed_items'],
					'settings'          => json_decode( $batch['settings'], true ),
				],
				'timestamp' => $batch['created_at'],
				'batch_id'  => (int) $batch['id'],
				'user_id'   => (int) $batch['user_id'],
				'status'    => $batch['status'],
			];
		}

		return $logs;
	}

	/**
	 * Get batch item logs.
	 *
	 * @param string $search Search query.
	 * @param string $status Status filter.
	 * @param int    $batch_id Batch ID filter.
	 * @param string $date_from Date from.
	 * @param string $date_to Date to.
	 * @return array
	 */
	private function get_batch_item_logs( $search = '', $status = '', $batch_id = 0, $date_from = '', $date_to = '' ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_batch_items';
		$where      = [ '1=1' ];
		$params     = [];

		if ( $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $batch_id ) {
			$where[]  = 'batch_id = %d';
			$params[] = $batch_id;
		}

		if ( $date_from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from;
		}

		if ( $date_to ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// Only use prepare() if we have params AND the query contains placeholders
		if ( ! empty( $params ) && ( strpos( $where_clause, '%' ) !== false ) ) {
			$query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT 1000", $params );
		} else {
			$query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT 1000";
		}

		$items = $wpdb->get_results( $query, ARRAY_A );

		$logs = [];
		foreach ( $items as $item ) {
			// Determine log level based on status
			$level = 'info';
			if ( $item['status'] === 'failed' ) {
				$level = 'error';
			} elseif ( $item['status'] === 'completed' ) {
				$level = 'success';
			} elseif ( $item['status'] === 'processing' ) {
				$level = 'info';
			} elseif ( $item['status'] === 'pending' ) {
				$level = 'warning';
			}

			$message = sprintf(
				'Batch Item #%d (Batch #%d): %s - %s',
				$item['id'],
				$item['batch_id'],
				$item['status'],
				$item['source_title']
			);

			if ( $item['log_message'] ) {
				$message .= ' - ' . $item['log_message'];
			}

			// Apply search filter
			if ( $search && stripos( $message, $search ) === false && stripos( $item['source_title'], $search ) === false && stripos( (string) $item['id'], $search ) === false ) {
				continue;
			}

			$logs[] = [
				'id'        => 'item_' . $item['id'],
				'type'      => 'batch_item',
				'level'     => $level,
				'message'   => $message,
				'context'   => [
					'item_id'          => (int) $item['id'],
					'batch_id'         => (int) $item['batch_id'],
					'generated_post_id' => $item['generated_post_id'] ? (int) $item['generated_post_id'] : null,
					'source_title'      => $item['source_title'],
					'status'            => $item['status'],
					'log_message'        => $item['log_message'],
					'checkpoint_step'   => $item['checkpoint_step'],
					'resume_count'      => (int) $item['resume_count'],
				],
				'timestamp' => $item['created_at'],
				'batch_id'  => (int) $item['batch_id'],
				'item_id'   => (int) $item['id'],
				'status'    => $item['status'],
			];
		}

		return $logs;
	}

	/**
	 * Get action scheduler logs.
	 *
	 * @param string $search Search query.
	 * @param string $date_from Date from.
	 * @param string $date_to Date to.
	 * @return array
	 */
	private function get_action_scheduler_logs( $search = '', $date_from = '', $date_to = '' ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'actionscheduler_logs';
		
		// Check if table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			return [];
		}

		$where  = [ '1=1' ];
		$params = [];

		if ( $date_from ) {
			$where[]  = 'log_date_gmt >= %s';
			$params[] = $date_from;
		}

		if ( $date_to ) {
			$where[]  = 'log_date_gmt <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// Only use prepare() if we have params AND the query contains placeholders
		if ( ! empty( $params ) && ( strpos( $where_clause, '%' ) !== false ) ) {
			$query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY log_date_gmt DESC LIMIT 1000", $params );
		} else {
			$query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY log_date_gmt DESC LIMIT 1000";
		}

		$action_logs = $wpdb->get_results( $query, ARRAY_A );

		$logs = [];
		foreach ( $action_logs as $log ) {
			// Determine log level from message
			$level = 'info';
			if ( stripos( $log['message'], 'error' ) !== false || stripos( $log['message'], 'failed' ) !== false ) {
				$level = 'error';
			} elseif ( stripos( $log['message'], 'warning' ) !== false ) {
				$level = 'warning';
			} elseif ( stripos( $log['message'], 'completed' ) !== false || stripos( $log['message'], 'success' ) !== false ) {
				$level = 'success';
			}

			// Apply search filter
			if ( $search && stripos( $log['message'], $search ) === false && stripos( (string) $log['action_id'], $search ) === false ) {
				continue;
			}

			$logs[] = [
				'id'        => 'action_' . $log['log_id'],
				'type'      => 'action_scheduler',
				'level'     => $level,
				'message'   => $log['message'],
				'context'   => [
					'log_id'        => (int) $log['log_id'],
					'action_id'     => (int) $log['action_id'],
					'log_date_gmt'  => $log['log_date_gmt'],
					'log_date_local' => $log['log_date_local'],
				],
				'timestamp' => $log['log_date_gmt'],
				'action_id' => (int) $log['action_id'],
				'status'    => 'info',
			];
		}

		return $logs;
	}

	/**
	 * Get logs statistics.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_logs_stats( $request ) {
		global $wpdb;

		// Get batch stats
		$batches_table = $wpdb->prefix . 'aebg_batches';
		$total_batches  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$batches_table}" );
		$completed_batches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$batches_table} WHERE status = 'completed'" );
		$failed_batches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$batches_table} WHERE status = 'failed'" );
		$in_progress_batches = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$batches_table} WHERE status IN ('in_progress', 'processing', 'pending')" );

		// Get batch item stats
		$items_table = $wpdb->prefix . 'aebg_batch_items';
		$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table}" );
		$failed_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table} WHERE status = 'failed'" );
		$completed_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table} WHERE status = 'completed'" );

		// Get action scheduler stats
		$actions_table = $wpdb->prefix . 'actionscheduler_logs';
		$total_actions = 0;
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$actions_table}'" ) === $actions_table ) {
			$total_actions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$actions_table}" );
		}

		// Calculate error rate
		$error_rate = 0;
		if ( $total_items > 0 ) {
			$error_rate = round( ( $failed_items / $total_items ) * 100, 2 );
		}

		// Get recent activity (last 24 hours)
		$recent_batches = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$batches_table} WHERE created_at >= %s",
			date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
		) );

		// Calculate total logs across all sources
		$total_logs = $total_batches + $total_items + $total_actions;
		
		// Get pending/processing items for warning count
		$pending_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table} WHERE status IN ('pending', 'processing')" );
		
		// Calculate logs by level more accurately
		$logs_by_level = [
			'info'    => max(0, $total_items - $failed_items - $completed_items - $pending_items), // Other items
			'error'   => $failed_items + $failed_batches,
			'success' => $completed_items + $completed_batches,
			'warning' => $pending_items + $in_progress_batches,
		];

		$data = [
			'total_batches'        => $total_batches,
			'completed_batches'    => $completed_batches,
			'failed_batches'       => $failed_batches,
			'in_progress_batches'  => $in_progress_batches,
			'total_items'          => $total_items,
			'completed_items'     => $completed_items,
			'failed_items'         => $failed_items,
			'total_actions'        => $total_actions,
			'total_logs'           => $total_logs,
			'error_rate'           => $error_rate,
			'recent_batches'       => $recent_batches,
			'logs_by_level'        => $logs_by_level,
			'logs_by_type'         => [
				'batch'           => $total_batches,
				'batch_item'      => $total_items,
				'action_scheduler' => $total_actions,
			],
		];

		$response = new \WP_REST_Response( $data, 200 );
		$response->set_headers( [
			'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
		] );

		return $response;
	}

	/**
	 * Export logs.
	 *
	 * @param array  $logs Logs data.
	 * @param string $format Export format (csv or json).
	 * @return \WP_REST_Response
	 */
	private function export_logs( $logs, $format = 'csv' ) {
		if ( $format === 'csv' ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=logs-' . date( 'Y-m-d' ) . '.csv' );

			$output = fopen( 'php://output', 'w' );

			// Add BOM for UTF-8
			fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

			// Headers
			fputcsv( $output, [ 'ID', 'Type', 'Level', 'Message', 'Timestamp', 'Batch ID', 'Item ID', 'Status' ] );

			// Data
			foreach ( $logs as $log ) {
				fputcsv( $output, [
					$log['id'],
					$log['type'],
					$log['level'],
					$log['message'],
					$log['timestamp'],
					$log['batch_id'] ?? '',
					$log['item_id'] ?? '',
					$log['status'] ?? '',
				] );
			}

			fclose( $output );
			exit;
		} else {
			// JSON export
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=logs-' . date( 'Y-m-d' ) . '.json' );

			echo wp_json_encode( $logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			exit;
		}
	}
}

