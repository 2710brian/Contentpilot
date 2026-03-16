/**
 * Featured Image Regeneration Controller
 *
 * Handles featured image regeneration with AI, including modal management,
 * API communication, and progress tracking.
 *
 * @package AEBG
 */
(function( $ ) {
	'use strict';

	class FeaturedImageRegenerator {
		constructor() {
			this.postId = null;
			
			// Ensure metabox appears above Categories
			this.ensureMetaboxPosition();
			this.postTitle = '';
			this.progressInterval = null;
			this.init();
		}

		init() {
			// Button click handler
			$( document ).on( 'click', '.aebg-regenerate-featured-image-btn', ( e ) => {
				const postId = $( e.target ).closest( '.aebg-regenerate-featured-image-btn' ).data( 'post-id' );
				this.openModal( postId );
			} );

			// Custom prompt toggle
			$( '#aebg-use-custom-prompt' ).on( 'change', () => {
				this.toggleCustomPrompt();
			} );

			// Character counter
			$( '#aebg-custom-prompt' ).on( 'input', () => {
				this.updateCharCounter();
			} );

			// Generate button
			$( '#aebg-generate-featured-image' ).on( 'click', () => {
				this.regenerate();
			} );

			// Modal close handlers
			$( '.aebg-modal-close' ).on( 'click', () => {
				this.closeModal();
			} );

			// Close on backdrop click
			$( '#aebg-featured-image-modal' ).on( 'click', ( e ) => {
				if ( $( e.target ).is( '#aebg-featured-image-modal' ) ) {
					this.closeModal();
				}
			} );

			// Cost & Time Estimation (Reuse from Generator V2)
			// Update estimates when model, quality, or size changes
			$( '#aebg-featured-image-model, #aebg-featured-image-size, input[name="aebg-featured-image-quality"]' ).on( 'change', () => {
				this.updateEstimate();
			} );

			// Initial estimate
			this.updateEstimate();
		}

		openModal( postId ) {
			this.postId = postId;
			this.postTitle = $( '#title' ).val() || '';

			// Show modal
			$( '#aebg-featured-image-modal' ).addClass( 'show' ).show();

			// Reset form
			$( '#aebg-use-custom-prompt' ).prop( 'checked', false );
			$( '#aebg-custom-prompt' ).val( '' );
			this.toggleCustomPrompt();

			// Show current featured image if exists
			this.loadCurrentFeaturedImage();

			// Update estimate
			this.updateEstimate();
		}

		loadCurrentFeaturedImage() {
			const $thumbnail = $( '#postimagediv .inside img' );
			if ( $thumbnail.length && $thumbnail.attr( 'src' ) ) {
				const imageUrl = $thumbnail.attr( 'src' );
				$( '#aebg-current-image-container' ).html(
					`<img src="${ imageUrl }" alt="Current featured image" style="max-width: 100%; border-radius: 8px; margin-top: 10px;" />`
				);
				$( '#aebg-current-image-preview' ).show();
			} else {
				$( '#aebg-current-image-preview' ).hide();
			}
		}

		toggleCustomPrompt() {
			const useCustom = $( '#aebg-use-custom-prompt' ).is( ':checked' );
			if ( useCustom ) {
				$( '#aebg-custom-prompt-group' ).slideDown();
				this.updateCharCounter();
			} else {
				$( '#aebg-custom-prompt-group' ).slideUp();
			}
		}

		updateCharCounter() {
			const length = $( '#aebg-custom-prompt' ).val().length;
			$( '#aebg-prompt-char-count' ).text( length );

			// Visual feedback for character limit
			const maxLength = 1000;
			const $counter = $( '#aebg-prompt-char-count' );
			if ( length > maxLength * 0.9 ) {
				$counter.addClass( 'warning' );
			} else {
				$counter.removeClass( 'warning' );
			}
		}

		async updateEstimate() {
			const model = $( '#aebg-featured-image-model' ).val() || 'dall-e-3';
			const quality = $( 'input[name="aebg-featured-image-quality"]:checked' ).val() || 'standard';
			const size = $( '#aebg-featured-image-size' ).val() || '1024x1024';

			try {
				const response = await $.ajax( {
					url: aebgFeaturedImage.restUrl + 'featured-image/estimate',
					method: 'POST',
					contentType: 'application/json',
					data: JSON.stringify( {
						image_model: model,
						image_quality: quality,
						image_size: size,
					} ),
					headers: {
						'X-WP-Nonce': aebgFeaturedImage.nonce,
					},
				} );

				if ( response.success && response.data ) {
					$( '#aebg-featured-image-cost' ).text( '$' + response.data.cost_per_image.toFixed( 2 ) );
					$( '#aebg-featured-image-time' ).text( response.data.estimated_time_formatted );
				}
			} catch ( error ) {
				console.error( 'Failed to update estimate:', error );
				// Fallback to client-side calculation
				this.calculateEstimateClientSide( model, quality, size );
			}
		}

		calculateEstimateClientSide( model, quality, size ) {
			// Reuse calculation logic from generator.js
			let costPerImage = 0;
			if ( model === 'dall-e-3' ) {
				costPerImage = quality === 'hd' ? 0.08 : 0.04;
			} else if ( model === 'dall-e-2' || model === 'nano-banana' || model === 'nano-banana-2' ) {
				costPerImage = 0.02;
			} else if ( model === 'nano-banana-pro' ) {
				costPerImage = 0.04;
			}

			let timePerImage = 15; // Base time
			if ( model === 'dall-e-3' ) {
				timePerImage = quality === 'hd' ? 25 : 15;
			} else if ( model === 'dall-e-2' ) {
				timePerImage = 10;
			} else if ( model === 'nano-banana' || model === 'nano-banana-2' ) {
				timePerImage = 8;
			} else if ( model === 'nano-banana-pro' ) {
				timePerImage = 15;
			}
			// Larger images take slightly longer
			if ( size === '1792x1024' || size === '1024x1792' ) {
				timePerImage += 3;
			}

			$( '#aebg-featured-image-cost' ).text( '$' + costPerImage.toFixed( 2 ) );
			$( '#aebg-featured-image-time' ).text( '~' + timePerImage + ' seconds' );
		}

		async regenerate() {
			const style = $( '#aebg-featured-image-style' ).val();
			const model = $( '#aebg-featured-image-model' ).val();
			const size = $( '#aebg-featured-image-size' ).val();
			const quality = $( 'input[name="aebg-featured-image-quality"]:checked' ).val();
			const useCustom = $( '#aebg-use-custom-prompt' ).is( ':checked' );
			const customPrompt = $( '#aebg-custom-prompt' ).val().trim();

			// Validation
			if ( useCustom && ! customPrompt ) {
				alert( 'Please enter a custom prompt or disable custom prompt mode.' );
				return;
			}

			if ( useCustom && customPrompt.length > 1000 ) {
				alert( 'Custom prompt must be 1000 characters or less.' );
				return;
			}

			// Disable button and show loading
			const $btn = $( '#aebg-generate-featured-image' );
			$btn.prop( 'disabled', true );
			$btn.find( '.aebg-btn-text' ).text( 'Scheduling...' );
			$btn.find( '.aebg-btn-spinner' ).show();

			// Show progress indicator
			$( '#aebg-featured-image-progress' ).show();
			$( '#aebg-featured-image-preview' ).hide();

			try {
				// Schedule regeneration via Action Scheduler (non-blocking)
				const response = await $.ajax( {
					url: aebgFeaturedImage.restUrl + 'featured-image/regenerate',
					method: 'POST',
					contentType: 'application/json',
					data: JSON.stringify( {
						post_id: this.postId,
						style: style,
						image_model: model,
						image_size: size,
						image_quality: quality,
						custom_prompt: useCustom ? customPrompt : null,
						use_custom_prompt: useCustom,
					} ),
					headers: {
						'X-WP-Nonce': aebgFeaturedImage.nonce,
					},
				} );

				if ( response.success && response.data.job_id ) {
					// Start polling for progress (reuses existing pattern from generator.js)
					this.startProgressTracking( response.data.job_id );
				} else {
					throw new Error( response.message || 'Failed to schedule regeneration' );
				}
			} catch ( error ) {
				console.error( 'Regeneration error:', error );
				this.showMessage(
					error.responseJSON?.message || error.message || 'Failed to schedule regeneration. Please try again.',
					'error'
				);
				// Re-enable button on error
				$btn.prop( 'disabled', false );
				$btn.find( '.aebg-btn-text' ).text( 'Generate Image' );
				$btn.find( '.aebg-btn-spinner' ).hide();
			}
		}

		startProgressTracking( jobId ) {
			// Reuse progress tracking pattern from generator.js
			const $progress = $( '#aebg-featured-image-progress' );
			const $btn = $( '#aebg-generate-featured-image' );

			// Update button text
			$btn.find( '.aebg-btn-text' ).text( 'Generating...' );

			// Show progress bar
			$progress.html( `
				<div class="aebg-progress-bar-container">
					<div class="aebg-progress-bar">
						<div class="aebg-progress-bar-inner" id="aebg-featured-progress-bar" style="width: 0%"></div>
					</div>
					<div class="aebg-progress-text" id="aebg-featured-progress-text">Scheduling regeneration...</div>
				</div>
			` ).show();

			// Clear any existing interval
			if ( this.progressInterval ) {
				clearInterval( this.progressInterval );
			}

			// Poll status every 2 seconds (same as generator.js)
			this.progressInterval = setInterval( async () => {
				try {
					const statusResponse = await $.ajax( {
						url: aebgFeaturedImage.restUrl + 'featured-image/status/' + jobId,
						method: 'GET',
						headers: {
							'X-WP-Nonce': aebgFeaturedImage.nonce,
						},
					} );

					if ( statusResponse.success ) {
						const status = statusResponse.data.status; // 'pending', 'processing', 'completed', 'failed'
						const progress = statusResponse.data.progress || 0;
						const message = statusResponse.data.message || '';

						// Update progress bar
						$( '#aebg-featured-progress-bar' ).css( 'width', progress + '%' );
						$( '#aebg-featured-progress-text' ).text( message );

						if ( status === 'completed' ) {
							clearInterval( this.progressInterval );
							this.progressInterval = null;

							// Update featured image in WordPress UI
							const attachmentId = statusResponse.data.attachment_id;
							const attachmentUrl = statusResponse.data.attachment_url;
							const thumbnailUrl = statusResponse.data.thumbnail_url || attachmentUrl;
							const promptUsed = statusResponse.data.prompt_used;

							this.updateFeaturedImage( attachmentId, attachmentUrl, thumbnailUrl );
							this.showPreview( attachmentUrl, promptUsed );
							this.showMessage( 'Image generated successfully!', 'success' );

							// Re-enable button
							$btn.prop( 'disabled', false );
							$btn.find( '.aebg-btn-text' ).text( 'Generate Image' );
							$btn.find( '.aebg-btn-spinner' ).hide();

						} else if ( status === 'failed' ) {
							clearInterval( this.progressInterval );
							this.progressInterval = null;
							const errorMessage = statusResponse.data.error_message || 'Generation failed';
							this.showMessage( errorMessage, 'error' );

							// Re-enable button
							$btn.prop( 'disabled', false );
							$btn.find( '.aebg-btn-text' ).text( 'Generate Image' );
							$btn.find( '.aebg-btn-spinner' ).hide();
						}
					}
				} catch ( error ) {
					console.error( 'Progress polling error:', error );
					// Continue polling on error (network issues, etc.)
				}
			}, 2000 ); // Poll every 2 seconds (same as generator.js)
		}

		updateFeaturedImage( attachmentId, url, thumbnailUrl = null ) {
			// Update WordPress featured image metabox hidden field
			$( '#_thumbnail_id' ).val( attachmentId );

			// Use provided thumbnail URL or construct it
			let finalThumbnailUrl = thumbnailUrl;
			if ( ! finalThumbnailUrl ) {
				// Try to get from URL pattern
				finalThumbnailUrl = url.replace( /-\d+x\d+\.(jpg|jpeg|png|gif|webp)$/i, '-150x150.$1' );
				// If no size in URL, add it before extension
				if ( finalThumbnailUrl === url ) {
					finalThumbnailUrl = url.replace( /\.(jpg|jpeg|png|gif|webp)$/i, '-150x150.$1' );
				}
			}

			// Update preview in metabox using WordPress's expected format
			const $preview = $( '#postimagediv .inside' );
			if ( $preview.length ) {
				// Remove existing content but preserve structure
				$preview.find( 'img' ).remove();
				$preview.find( 'a.thickbox' ).remove();
				$preview.find( '.remove-post-thumbnail' ).parent().remove();

				// Add new image preview
				$preview.prepend( `
					<p class="hide-if-no-js">
						<a href="${ url }" class="thickbox" title="Featured Image">
							<img src="${ finalThumbnailUrl }" alt="" style="max-width: 100%; height: auto;" />
						</a>
					</p>
					<p class="hide-if-no-js">
						<a href="#" class="remove-post-thumbnail">Remove featured image</a>
					</p>
				` );

				// Trigger WordPress's featured image update event if available
				if ( typeof wp !== 'undefined' && wp.media && wp.media.featuredImage ) {
					wp.media.featuredImage.set( attachmentId );
				}

				// Trigger custom event for other plugins/themes
				$( document ).trigger( 'aebg_featured_image_updated', [ attachmentId, url ] );
			} else {
				// If metabox doesn't exist yet, try to refresh the page section
				// This is a fallback - normally the metabox should exist
				console.warn( 'Featured image metabox not found, page may need refresh' );
			}

			// Reload current image preview in modal
			this.loadCurrentFeaturedImage();
		}

		showPreview( imageUrl, promptUsed ) {
			$( '#aebg-featured-image-preview' ).html( `
				<div class="aebg-preview-container">
					<h4>Generated Image</h4>
					<img src="${ imageUrl }" alt="Generated featured image" style="max-width: 100%; border-radius: 8px;" />
					${ promptUsed ? `<p class="aebg-prompt-used"><strong>Prompt used:</strong> ${ this.escapeHtml( promptUsed ) }</p>` : '' }
				</div>
			` ).show();
		}

		showMessage( message, type ) {
			// Show success/error message
			const $msg = $( `<div class="aebg-message aebg-message-${ type }">${ this.escapeHtml( message ) }</div>` );
			$( '.aebg-modal-body' ).prepend( $msg );
			setTimeout( () => $msg.fadeOut( () => $msg.remove() ), 5000 );
		}

		escapeHtml( text ) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			};
			return text.replace( /[&<>"']/g, ( m ) => map[ m ] );
		}

		closeModal() {
			// Clear progress interval
			if ( this.progressInterval ) {
				clearInterval( this.progressInterval );
				this.progressInterval = null;
			}

			$( '#aebg-featured-image-modal' ).removeClass( 'show' ).hide();
		}

		ensureMetaboxPosition() {
			// Ensure metabox appears above Categories
			const $metabox = $( '#aebg_featured_image_regenerate' );
			const $categories = $( '#categoriesdiv' );
			
			if ( $metabox.length && $categories.length ) {
				// Move our metabox before Categories
				$metabox.insertBefore( $categories );
			}
		}
	}

	$( document ).ready( () => {
		new FeaturedImageRegenerator();
	} );
	
	// Also ensure position on postboxes initialization (WordPress core event)
	$( document ).on( 'postboxes-setup', function() {
		const $metabox = $( '#aebg_featured_image_regenerate' );
		const $categories = $( '#categoriesdiv' );
		
		if ( $metabox.length && $categories.length ) {
			$metabox.insertBefore( $categories );
		}
	} );
})( jQuery );

