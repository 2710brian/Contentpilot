<?php

namespace AEBG\Admin;

/**
 * Bulk Featured Image Finder
 * 
 * Provides bulk functionality to automatically find and assign featured images
 * from the media library based on post titles and content.
 *
 * @package AEBG\Admin
 */
class BulkFeaturedImageFinder {
	/**
	 * BulkFeaturedImageFinder constructor.
	 */
	public function __construct() {
		// Register hooks immediately (not in admin_init)
		// bulk_actions filter needs to be registered early
		add_filter( 'bulk_actions-edit-post', [ $this, 'add_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-post', [ $this, 'handle_bulk_action' ], 10, 3 );
		
		// Enqueue assets
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		
		// Add admin notices
		add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );
	}

	/**
	 * Add bulk action to posts list.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public function add_bulk_action( $actions ) {
		// Add the bulk action - WordPress will only show it on the correct screen
		$actions['aebg_find_featured_image'] = __( 'Find Featured Image from Library', 'aebg' );
		return $actions;
	}

	/**
	 * Handle bulk action execution.
	 *
	 * @param string $redirect_url Redirect URL after processing.
	 * @param string $action The action being taken.
	 * @param array  $post_ids Array of post IDs.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		// Check if this is our action
		if ( 'aebg_find_featured_image' !== $action ) {
			return $redirect_url;
		}

		// Verify nonce
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-posts' ) ) {
			return $redirect_url;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			return $redirect_url;
		}

		// Process posts
		$results = [
			'success' => 0,
			'skipped' => 0,
			'failed'  => 0,
		];

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			
			// Check if user can edit this post
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$results['skipped']++;
				continue;
			}

			// Skip if already has featured image
			// To replace existing featured images, remove them first or modify this check
			if ( has_post_thumbnail( $post_id ) ) {
				$results['skipped']++;
				continue;
			}

			// Find matching image
			$attachment_id = $this->find_matching_image( $post_id );

			if ( $attachment_id ) {
				// Set as featured image
				$result = set_post_thumbnail( $post_id, $attachment_id );
				
				if ( $result ) {
					$results['success']++;
				} else {
					$results['failed']++;
				}
			} else {
				$results['failed']++;
			}
		}

		// Store results in transient for admin notice
		set_transient(
			'aebg_bulk_featured_image_results_' . get_current_user_id(),
			$results,
			30 // 30 seconds
		);

		// Add query args to redirect URL
		$redirect_url = add_query_arg(
			[
				'aebg_featured_image_processed' => '1',
			],
			$redirect_url
		);

		return $redirect_url;
	}

	/**
	 * Find matching image from media library for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	private function find_matching_image( $post_id ) {
		$post = get_post( $post_id );
		
		if ( ! $post ) {
			return false;
		}

		// Get search terms from post
		$search_terms = $this->extract_search_terms( $post );

		if ( empty( $search_terms ) ) {
			return false;
		}

		// Search media library
		$matches = $this->search_media_library( $search_terms );

		if ( empty( $matches ) ) {
			return false;
		}

		// Score and rank matches
		$ranked_matches = $this->rank_matches( $matches, $post, $search_terms );

		// Return best match
		if ( ! empty( $ranked_matches ) ) {
			return $ranked_matches[0]['id'];
		}

		return false;
	}

	/**
	 * Extract search terms from post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Array of search terms.
	 */
	private function extract_search_terms( $post ) {
		$terms = [];

		// Add post title words
		if ( ! empty( $post->post_title ) ) {
			$title_words = $this->extract_keywords( $post->post_title );
			$terms = array_merge( $terms, $title_words );
		}

		// Add post content keywords (first 500 chars)
		if ( ! empty( $post->post_content ) ) {
			$content_snippet = wp_strip_all_tags( $post->post_content );
			$content_snippet = substr( $content_snippet, 0, 500 );
			$content_words = $this->extract_keywords( $content_snippet );
			$terms = array_merge( $terms, $content_words );
		}

		// Add category names
		$categories = get_the_category( $post->ID );
		foreach ( $categories as $category ) {
			$terms[] = $category->name;
		}

		// Remove duplicates and empty values
		$terms = array_unique( array_filter( $terms ) );

		// Remove very short terms (less than 3 characters)
		$terms = array_filter( $terms, function( $term ) {
			return strlen( $term ) >= 3;
		} );

		return array_values( $terms );
	}

	/**
	 * Extract keywords from text.
	 *
	 * @param string $text Text to extract keywords from.
	 * @return array Array of keywords.
	 */
	private function extract_keywords( $text ) {
		// Remove special characters and convert to lowercase
		$text = preg_replace( '/[^a-zA-Z0-9\s]/', ' ', $text );
		$text = strtolower( $text );

		// Split into words
		$words = preg_split( '/\s+/', $text );

		// Remove common stop words
		$stop_words = [ 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been', 'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should', 'could', 'may', 'might', 'must', 'can', 'this', 'that', 'these', 'those' ];
		$words = array_filter( $words, function( $word ) use ( $stop_words ) {
			return ! in_array( $word, $stop_words, true ) && strlen( $word ) >= 3;
		} );

		return array_values( $words );
	}

	/**
	 * Search media library for matching images.
	 *
	 * @param array $search_terms Array of search terms.
	 * @return array Array of attachment objects.
	 */
	private function search_media_library( $search_terms ) {
		$matches = [];

		// Strategy: Try targeted search first, then fall back to broader search
		// This is more efficient for large libraries (4000+ images)
		
		// First, try searching by keywords in title/description (WordPress search)
		$targeted_matches = $this->search_by_keywords( $search_terms );
		
		// If we found good matches, use those
		if ( ! empty( $targeted_matches ) && count( $targeted_matches ) >= 5 ) {
			$attachments = $targeted_matches;
		} else {
			// Fall back to searching all images, but optimize the query
			$args = [
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => -1, // No limit - search all images
				'post_status'    => 'inherit',
				'orderby'        => 'date',
				'order'          => 'DESC', // Prefer newer images
				'no_found_rows'  => true, // Skip counting total for performance
				'update_post_meta_cache' => false, // Skip meta cache for speed
				'update_post_term_cache' => false, // Skip term cache for speed
			];

			$attachments = get_posts( $args );
		}

		// Pre-process search terms to lowercase for efficiency
		$search_terms_lower = array_map( 'strtolower', $search_terms );
		
		// Limit to top 20 matches to avoid processing too many
		// We'll score all but only keep the best ones
		$top_matches = [];
		$min_score_threshold = 3; // Minimum score to consider

		foreach ( $attachments as $attachment ) {
			$score = 0;
			
			// Get filename efficiently (most important check)
			$file_path = get_attached_file( $attachment->ID );
			$filename = $file_path ? basename( $file_path ) : '';
			$filename_lower = strtolower( $filename );
			
			// Quick check: if filename doesn't match any term, skip early
			$filename_match = false;
			foreach ( $search_terms_lower as $term ) {
				if ( strpos( $filename_lower, $term ) !== false ) {
					$score += 5;
					$filename_match = true;
				}
			}
			
			// If no filename match and we already have good matches, skip this one
			if ( ! $filename_match && count( $top_matches ) >= 10 ) {
				continue;
			}

			$match_data = [
				'id'          => $attachment->ID,
				'title'       => $attachment->post_title,
				'filename'    => $filename,
				'alt_text'    => '', // Lazy load if needed
				'description' => $attachment->post_content,
				'caption'     => $attachment->post_excerpt,
				'score'       => 0,
			];

			// Check title
			$title_lower = strtolower( $match_data['title'] );
			foreach ( $search_terms_lower as $term ) {
				if ( strpos( $title_lower, $term ) !== false ) {
					$score += 4;
				}
			}

			// Check description
			if ( ! empty( $match_data['description'] ) ) {
				$desc_lower = strtolower( $match_data['description'] );
				foreach ( $search_terms_lower as $term ) {
					if ( strpos( $desc_lower, $term ) !== false ) {
						$score += 2;
					}
				}
			}

			// Check caption
			if ( ! empty( $match_data['caption'] ) ) {
				$caption_lower = strtolower( $match_data['caption'] );
				foreach ( $search_terms_lower as $term ) {
					if ( strpos( $caption_lower, $term ) !== false ) {
						$score += 2;
					}
				}
			}

			// Only check alt text if score is promising (lazy load for performance)
			if ( $score >= $min_score_threshold ) {
				$alt_text = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
				if ( ! empty( $alt_text ) ) {
					$alt_lower = strtolower( $alt_text );
					foreach ( $search_terms_lower as $term ) {
						if ( strpos( $alt_lower, $term ) !== false ) {
							$score += 3;
						}
					}
					$match_data['alt_text'] = $alt_text;
				}
			}

			// Only include matches with a score >= threshold
			if ( $score >= $min_score_threshold ) {
				$match_data['score'] = $score;
				$top_matches[] = $match_data;
				
				// Keep only top 20 matches sorted by score
				if ( count( $top_matches ) > 20 ) {
					usort( $top_matches, function( $a, $b ) {
						return $b['score'] - $a['score'];
					} );
					$top_matches = array_slice( $top_matches, 0, 20 );
				}
			}
		}
		
		// Sort final matches by score
		usort( $top_matches, function( $a, $b ) {
			return $b['score'] - $a['score'];
		} );
		
		$matches = $top_matches;

		return $matches;
	}

	/**
	 * Search media library by keywords using WordPress search.
	 * More efficient for large libraries.
	 *
	 * @param array $search_terms Array of search terms.
	 * @return array Array of attachment objects.
	 */
	private function search_by_keywords( $search_terms ) {
		if ( empty( $search_terms ) ) {
			return [];
		}

		// Build search query from terms
		$search_query = implode( ' ', array_slice( $search_terms, 0, 5 ) ); // Use top 5 terms

		$args = [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'posts_per_page' => 100, // Limit results from keyword search
			'post_status'    => 'inherit',
			's'              => $search_query, // WordPress search
			'orderby'        => 'relevance', // WordPress will rank by relevance
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		return get_posts( $args );
	}

	/**
	 * Rank matches by relevance.
	 *
	 * @param array    $matches Array of match data.
	 * @param \WP_Post $post Post object.
	 * @param array    $search_terms Search terms used.
	 * @return array Ranked matches.
	 */
	private function rank_matches( $matches, $post, $search_terms ) {
		// Sort by score (descending)
		usort( $matches, function( $a, $b ) {
			return $b['score'] - $a['score'];
		} );

		// Additional ranking: prefer images uploaded around the same time as the post
		$post_date = strtotime( $post->post_date );
		foreach ( $matches as &$match ) {
			$attachment = get_post( $match['id'] );
			if ( $attachment ) {
				$attachment_date = strtotime( $attachment->post_date );
				$date_diff = abs( $post_date - $attachment_date );
				
				// Boost score if uploaded within 7 days of post
				if ( $date_diff < ( 7 * DAY_IN_SECONDS ) ) {
					$match['score'] += 2;
				}
			}
		}
		unset( $match );

		// Re-sort after date boost
		usort( $matches, function( $a, $b ) {
			return $b['score'] - $a['score'];
		} );

		return $matches;
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : 'post';
		if ( 'post' !== $post_type ) {
			return;
		}

		$cache_buster = microtime( true );

		wp_enqueue_style(
			'aebg-bulk-featured-image',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/bulk-featured-image.css',
			[],
			'1.0.0.' . $cache_buster
		);
	}

	/**
	 * Show admin notices.
	 */
	public function show_admin_notices() {
		// Only show on posts list page
		$screen = get_current_screen();
		if ( ! $screen || 'edit-post' !== $screen->id ) {
			return;
		}

		// Check if we just processed
		if ( ! isset( $_GET['aebg_featured_image_processed'] ) ) {
			return;
		}

		// Get results
		$results = get_transient( 'aebg_bulk_featured_image_results_' . get_current_user_id() );

		if ( ! $results ) {
			return;
		}

		// Delete transient
		delete_transient( 'aebg_bulk_featured_image_results_' . get_current_user_id() );

		// Build message
		$message_parts = [];
		
		if ( $results['success'] > 0 ) {
			$message_parts[] = sprintf(
				/* translators: %d: number of posts */
				_n( '%d post assigned a featured image', '%d posts assigned featured images', $results['success'], 'aebg' ),
				$results['success']
			);
		}

		if ( $results['skipped'] > 0 ) {
			$message_parts[] = sprintf(
				/* translators: %d: number of posts */
				_n( '%d post skipped (already has featured image)', '%d posts skipped (already have featured images)', $results['skipped'], 'aebg' ),
				$results['skipped']
			);
		}

		if ( $results['failed'] > 0 ) {
			$message_parts[] = sprintf(
				/* translators: %d: number of posts */
				_n( '%d post could not be matched', '%d posts could not be matched', $results['failed'], 'aebg' ),
				$results['failed']
			);
		}

		if ( ! empty( $message_parts ) ) {
			$notice_type = $results['failed'] > 0 && $results['success'] === 0 ? 'error' : 'success';
			?>
			<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
				<p><strong><?php esc_html_e( 'Bulk Featured Image Assignment', 'aebg' ); ?>:</strong> <?php echo esc_html( implode( ', ', $message_parts ) ); ?>.</p>
			</div>
			<?php
		}
	}
}

