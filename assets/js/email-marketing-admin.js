/**
 * Email Marketing Admin JavaScript
 * 
 * @package AEBG
 */

(function($) {
	'use strict';
	
	// Ensure jQuery is available
	if (typeof $ === 'undefined') {
		return;
	}
	
	$(document).ready(function() {
		// Handle form submissions
		$('.aebg-email-signup-form').on('submit', function(e) {
			e.preventDefault();
			
			const $form = $(this);
			const $message = $form.find('.aebg-form-message');
			const formData = $form.serialize();
			
			$.ajax({
				url: aebgEmailMarketing.ajaxUrl,
				type: 'POST',
				data: {
					action: 'aebg_email_signup',
					nonce: aebgEmailMarketing.nonce,
					...formData
				},
				success: function(response) {
					if (response.success) {
						$message.html('<div class="notice notice-success">' + response.data.message + '</div>');
						$form[0].reset();
					} else {
						$message.html('<div class="notice notice-error">' + response.data.message + '</div>');
					}
				},
				error: function() {
					$message.html('<div class="notice notice-error">An error occurred. Please try again.</div>');
				}
			});
		});
		
		// Handle subscriber removal
		$(document).on('click', '.aebg-remove-subscriber', function(e) {
			e.preventDefault();
			
			const $link = $(this);
			const subscriberId = $link.data('id');
			const subscriberEmail = $link.closest('tr').find('td:first a').text() || $link.closest('tr').find('td:first').text();
			
			if (!confirm('Are you sure you want to remove ' + (subscriberEmail ? subscriberEmail : 'this subscriber') + '?')) {
				return;
			}
			
			// Add loading state
			$link.addClass('aebg-loading').prop('disabled', true);
			
			$.ajax({
				url: aebgEmailMarketing.ajaxUrl,
				type: 'POST',
				data: {
					action: 'aebg_remove_subscriber',
					nonce: aebgEmailMarketing.nonce,
					subscriber_id: subscriberId
				},
				success: function(response) {
					if (response.success) {
						$link.closest('tr').fadeOut(300, function() {
							$(this).remove();
							// Check if table is now empty
							if ($('.aebg-email-subscribers-page tbody tr').length === 0) {
								location.reload();
							}
						});
					} else {
						alert(response.data.message || 'An error occurred while removing the subscriber.');
						$link.removeClass('aebg-loading').prop('disabled', false);
					}
				},
				error: function(xhr, status, error) {
					alert('An error occurred. Please try again.');
					$link.removeClass('aebg-loading').prop('disabled', false);
				}
			});
		});
		
		// Handle template page buttons
		// Create template button
		$(document).on('click', '.aebg-create-template-btn', function(e) {
			e.preventDefault();
			if (typeof aebgEmailMarketing !== 'undefined' && aebgEmailMarketing.adminUrl) {
				window.location.href = aebgEmailMarketing.adminUrl + '?page=aebg-email-templates&action=create';
			} else {
				// Fallback: construct from current URL
				const adminUrl = window.location.href.split('?')[0].replace(/\/[^\/]*$/, '/admin.php');
				window.location.href = adminUrl + '?page=aebg-email-templates&action=create';
			}
		});
		
		// Edit template button
		$(document).on('click', '.aebg-edit-template', function(e) {
			e.preventDefault();
			const templateId = $(this).data('template-id');
			if (templateId) {
				if (typeof aebgEmailMarketing !== 'undefined' && aebgEmailMarketing.adminUrl) {
					window.location.href = aebgEmailMarketing.adminUrl + '?page=aebg-email-templates&action=edit&id=' + templateId;
				} else {
					// Fallback: construct from current URL
					const adminUrl = window.location.href.split('?')[0].replace(/\/[^\/]*$/, '/admin.php');
					window.location.href = adminUrl + '?page=aebg-email-templates&action=edit&id=' + templateId;
				}
			}
		});
		
		// Preview template button
		$(document).on('click', '.aebg-preview-template', function(e) {
			e.preventDefault();
			const templateId = $(this).data('template-id');
			if (templateId) {
				let previewUrl;
				if (typeof aebgEmailMarketing !== 'undefined' && aebgEmailMarketing.adminUrl) {
					previewUrl = aebgEmailMarketing.adminUrl + '?page=aebg-email-templates&action=preview&id=' + templateId;
				} else {
					// Fallback: construct from current URL
					const adminUrl = window.location.href.split('?')[0].replace(/\/[^\/]*$/, '/admin.php');
					previewUrl = adminUrl + '?page=aebg-email-templates&action=preview&id=' + templateId;
				}
				window.open(previewUrl, '_blank', 'width=800,height=600,scrollbars=yes');
			}
		});
		
		// Handle campaigns page buttons
		// Create campaign button
		$(document).on('click', '.aebg-create-campaign-btn', function(e) {
			e.preventDefault();
			if (typeof aebgEmailMarketing !== 'undefined' && aebgEmailMarketing.adminUrl) {
				window.location.href = aebgEmailMarketing.adminUrl + '?page=aebg-email-campaigns&action=create';
			} else {
				const adminUrl = window.location.href.split('?')[0].replace(/\/[^\/]*$/, '/admin.php');
				window.location.href = adminUrl + '?page=aebg-email-campaigns&action=create';
			}
		});
		
		// View campaign button (in campaigns table)
		$(document).on('click', '.aebg-view-campaign', function(e) {
			e.preventDefault();
			const campaignId = $(this).data('campaign-id');
			if (campaignId) {
				if (typeof aebgEmailMarketing !== 'undefined' && aebgEmailMarketing.adminUrl) {
					window.location.href = aebgEmailMarketing.adminUrl + '?page=aebg-email-campaigns&action=view&id=' + campaignId;
				} else {
					const adminUrl = window.location.href.split('?')[0].replace(/\/[^\/]*$/, '/admin.php');
					window.location.href = adminUrl + '?page=aebg-email-campaigns&action=view&id=' + campaignId;
				}
			}
		});
		
		// Handle lists page buttons
		// Create list button
		$(document).on('click', '.aebg-create-list-btn', function(e) {
			e.preventDefault();
			if (typeof aebgEmailMarketing !== 'undefined' && aebgEmailMarketing.adminUrl) {
				window.location.href = aebgEmailMarketing.adminUrl + '?page=aebg-email-marketing&action=create_list';
			} else {
				const adminUrl = window.location.href.split('?')[0].replace(/\/[^\/]*$/, '/admin.php');
				window.location.href = adminUrl + '?page=aebg-email-marketing&action=create_list';
			}
		});
	});
	
})(jQuery);

