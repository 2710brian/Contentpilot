/**
 * Email Signup Widget JavaScript
 * 
 * Handles form submissions for email signup forms
 * 
 * @package AEBG
 */

(function($) {
	'use strict';
	
	$(document).ready(function() {
		$('.aebg-email-signup-form').on('submit', function(e) {
			e.preventDefault();
			
			const $form = $(this);
			const $message = $form.find('.aebg-form-message');
			const $submit = $form.find('.aebg-form-submit');
			const formData = $form.serialize();
			
			// Disable submit button
			$submit.prop('disabled', true).text('Subscribing...');
			$message.html('');
			
			$.ajax({
				url: ajaxurl || '/wp-admin/admin-ajax.php',
				type: 'POST',
				data: {
					action: 'aebg_email_signup',
					nonce: $form.find('input[name="_aebg_nonce"]').val(),
					...formData
				},
				success: function(response) {
					if (response.success) {
						$message.html('<div class="notice notice-success">' + (response.data.message || 'Thank you for subscribing!') + '</div>');
						$form[0].reset();
					} else {
						$message.html('<div class="notice notice-error">' + (response.data.message || 'An error occurred. Please try again.') + '</div>');
					}
				},
				error: function() {
					$message.html('<div class="notice notice-error">An error occurred. Please try again.</div>');
				},
				complete: function() {
					$submit.prop('disabled', false).text($submit.data('original-text') || 'Subscribe');
				}
			});
		});
	});
	
})(jQuery);

