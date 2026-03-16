/**
 * Bulk Categories JavaScript
 * Handles bulk category assignment in WordPress posts list
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Wait for WordPress to render the bulk edit form
		// The form is added dynamically when posts are selected
		
		// Function to enhance the category selector
		function enhanceCategorySelector() {
			var $categorySelect = $('.aebg-bulk-category-multiselect');
			
			if ( $categorySelect.length === 0 ) {
				return;
			}
			
			// Add visual feedback for selected items
			$categorySelect.on('change', function() {
				var selectedCount = $(this).val() ? $(this).val().length : 0;
				var $description = $(this).siblings('.description');
				
				if ( selectedCount > 0 ) {
					var strings = (typeof aebgBulkCategories !== 'undefined' && aebgBulkCategories.strings) ? aebgBulkCategories.strings : {};
					$description.text(
						selectedCount === 1 
							? (strings.oneSelected || '1 category selected')
							: (strings.manySelected || '{count} categories selected').replace('{count}', selectedCount)
					);
				} else {
					var strings = (typeof aebgBulkCategories !== 'undefined' && aebgBulkCategories.strings) ? aebgBulkCategories.strings : {};
					$description.text(
						strings.noneSelected || 
						'Hold Ctrl/Cmd to select multiple categories. Selected categories will be added to all selected posts.'
					);
				}
			});
		}
		
		// Watch for when posts are selected (WordPress shows bulk edit form)
		$(document).on('click', '#doaction, #doaction2', function() {
			var action = $(this).siblings('select').val();
			if (action === 'edit') {
				setTimeout(function() {
					enhanceCategorySelector();
				}, 300);
			}
		});
		
		// Also use MutationObserver for better compatibility
		if ( typeof MutationObserver !== 'undefined' ) {
			var observer = new MutationObserver(function(mutations) {
				mutations.forEach(function(mutation) {
					if ( mutation.addedNodes.length > 0 ) {
						var $bulkEdit = $(mutation.addedNodes).find('#bulk-edit');
						if ( $bulkEdit.length > 0 ) {
							setTimeout(function() {
								enhanceCategorySelector();
							}, 200);
						}
					}
				});
			});
			
			observer.observe(document.body, {
				childList: true,
				subtree: true
			});
		}
		
		// Initial check in case form is already visible
		setTimeout(function() {
			enhanceCategorySelector();
		}, 500);
		
		// Add validation before form submission
		$(document).on('click', '#bulk-edit .button-primary', function(e) {
			var $categorySelect = $('.aebg-bulk-category-multiselect');
			var $categoryAction = $('input[name="aebg_bulk_category_action"]:checked');
			
			// Only validate if category selector is present and visible
			if ( $categorySelect.length > 0 && $categorySelect.is(':visible') ) {
				var selectedCategories = $categorySelect.val();
				var action = $categoryAction.val();
				
				// Warn if trying to remove/replace but no categories selected
				if ( ( action === 'remove' || action === 'replace' ) && ( !selectedCategories || selectedCategories.length === 0 ) ) {
					if ( !confirm( 'No categories selected. This will remove all categories from the selected posts. Continue?' ) ) {
						e.preventDefault();
						return false;
					}
				}
			}
		});
	});
	
})(jQuery);

