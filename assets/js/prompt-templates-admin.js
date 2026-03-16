/**
 * Prompt Templates Admin JavaScript
 * 
 * Handles template management UI interactions
 */

(function($) {
	'use strict';

	const PromptTemplatesAdmin = {
		currentPage: 1,
		perPage: 20,
		currentFilters: {
			search: '',
			category: '',
			orderby: 'created_at',
			order: 'DESC',
			showPublic: false
		},
		templates: [],
		categories: [],

		init: function() {
			this.bindEvents();
			this.loadCategories();
			this.loadTemplates();
		},

		bindEvents: function() {
			const self = this;

			// Create template button
			$(document).on('click', '#aebg-create-template-btn', function() {
				self.openModal();
			});

			// Modal close
			$(document).on('click', '.aebg-modal-close, #aebg-template-cancel-btn', function() {
				self.closeModal();
			});

			// Click outside modal to close
			$(document).on('click', '#aebg-template-modal', function(e) {
				if ($(e.target).is('#aebg-template-modal')) {
					self.closeModal();
				}
			});

			// Save template
			$(document).on('click', '#aebg-template-save-btn', function() {
				self.saveTemplate();
			});

			// Search
			let searchTimeout;
			$(document).on('input', '#aebg-template-search', function() {
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(function() {
					self.currentFilters.search = $(this).val();
					self.currentPage = 1;
					self.loadTemplates();
				}.bind(this), 300);
			});

			// Category filter
			$(document).on('change', '#aebg-template-category-filter', function() {
				self.currentFilters.category = $(this).val();
				self.currentPage = 1;
				self.loadTemplates();
			});

			// Sort
			$(document).on('change', '#aebg-template-sort', function() {
				const val = $(this).val().split('-');
				self.currentFilters.orderby = val[0];
				self.currentFilters.order = val[1].toUpperCase();
				self.currentPage = 1;
				self.loadTemplates();
			});

			// Show public only
			$(document).on('change', '#aebg-show-public-only', function() {
				self.currentFilters.showPublic = $(this).is(':checked');
				self.currentPage = 1;
				self.loadTemplates();
			});

			// Template card actions
			$(document).on('click', '.aebg-btn-edit', function() {
				const templateId = $(this).closest('.aebg-template-card').data('template-id');
				self.editTemplate(templateId);
			});

			$(document).on('click', '.aebg-btn-duplicate', function() {
				const templateId = $(this).closest('.aebg-template-card').data('template-id');
				self.duplicateTemplate(templateId);
			});

			$(document).on('click', '.aebg-btn-delete', function() {
				const templateId = $(this).closest('.aebg-template-card').data('template-id');
				self.deleteTemplate(templateId);
			});

			// Category custom input
			$(document).on('change', '#aebg-template-category', function() {
				if ($(this).val() === 'custom') {
					$('#aebg-template-category-custom').show();
				} else {
					$('#aebg-template-category-custom').hide();
				}
			});
		},

		loadTemplates: function() {
			const self = this;
			const $grid = $('#aebg-templates-grid');
			
			$grid.html('<div class="aebg-loading"><span class="spinner is-active"></span> Loading templates...</div>');

			const params = {
				per_page: this.perPage,
				page: this.currentPage,
				orderby: this.currentFilters.orderby,
				order: this.currentFilters.order
			};

			if (this.currentFilters.search) {
				params.search = this.currentFilters.search;
			}

			if (this.currentFilters.category) {
				params.category = this.currentFilters.category;
			}

			$.ajax({
				url: aebgPromptTemplates.rest_url,
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', aebgPromptTemplates.rest_nonce);
				},
				data: params,
				success: function(response) {
					self.templates = Array.isArray(response) ? response : [];
					self.renderTemplates();
					self.renderPagination(response.total || self.templates.length);
				},
				error: function(xhr) {
					let errorMsg = 'Failed to load templates.';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					}
					$grid.html('<div class="aebg-error">' + errorMsg + '</div>');
				}
			});
		},

		loadCategories: function() {
			const self = this;
			$.ajax({
				url: aebgPromptTemplates.rest_url + 'categories',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', aebgPromptTemplates.rest_nonce);
				},
				success: function(response) {
					self.categories = Array.isArray(response) ? response : [];
					self.renderCategoryFilter();
				}
			});
		},

		renderTemplates: function() {
			const $grid = $('#aebg-templates-grid');
			
			if (this.templates.length === 0) {
				$grid.html('<div class="aebg-empty-state"><p>No templates found. Create your first template!</p></div>');
				return;
			}

			let html = '';
			this.templates.forEach(function(template) {
				html += this.renderTemplateCard(template);
			}.bind(this));

			$grid.html(html);
		},

		renderTemplateCard: function(template) {
			const promptPreview = template.prompt.length > 150 
				? template.prompt.substring(0, 150) + '...' 
				: template.prompt;
			
			const lastUsed = template.last_used_at 
				? this.getRelativeTime(template.last_used_at) 
				: '';

			return `
				<div class="aebg-template-card" data-template-id="${template.id}">
					<div class="aebg-template-card-header">
						<h3 class="aebg-template-card-title">${this.escapeHtml(template.name)}</h3>
						<div class="aebg-template-card-actions">
							<button type="button" class="aebg-btn-icon aebg-btn-edit" data-action="edit" title="Edit">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<button type="button" class="aebg-btn-icon aebg-btn-duplicate" data-action="duplicate" title="Duplicate">
								<span class="dashicons dashicons-admin-page"></span>
							</button>
							<button type="button" class="aebg-btn-icon aebg-btn-delete" data-action="delete" title="Delete">
								<span class="dashicons dashicons-trash"></span>
							</button>
						</div>
					</div>
					<div class="aebg-template-card-body">
						${template.description ? '<p class="aebg-template-card-description">' + this.escapeHtml(template.description) + '</p>' : ''}
						<div class="aebg-template-card-preview">
							<pre>${this.escapeHtml(promptPreview)}</pre>
						</div>
					</div>
					<div class="aebg-template-card-footer">
						<div class="aebg-template-card-meta">
							<span class="aebg-template-category">${this.escapeHtml(template.category || 'general')}</span>
							${template.is_public ? '<span class="aebg-template-badge aebg-template-public">Public</span>' : ''}
						</div>
						<div class="aebg-template-card-stats">
							<span class="aebg-template-usage">
								<span class="dashicons dashicons-chart-line"></span>
								${template.usage_count || 0}
							</span>
							${lastUsed ? '<span class="aebg-template-last-used">Used ' + lastUsed + '</span>' : ''}
						</div>
					</div>
				</div>
			`;
		},

		renderCategoryFilter: function() {
			const $select = $('#aebg-template-category-filter');
			let html = '<option value="">All Categories</option>';
			
			this.categories.forEach(function(category) {
				html += `<option value="${this.escapeHtml(category)}">${this.escapeHtml(category)}</option>`;
			}.bind(this));

			$select.html(html);
		},

		renderPagination: function(total) {
			const $pagination = $('#aebg-templates-pagination');
			const totalPages = Math.ceil(total / this.perPage);
			
			if (totalPages <= 1) {
				$pagination.html('');
				return;
			}

			let html = '<div class="aebg-pagination">';
			
			// Previous button
			if (this.currentPage > 1) {
				html += `<button type="button" class="button" data-page="${this.currentPage - 1}">Previous</button>`;
			}

			// Page numbers
			for (let i = 1; i <= totalPages; i++) {
				if (i === 1 || i === totalPages || (i >= this.currentPage - 2 && i <= this.currentPage + 2)) {
					const active = i === this.currentPage ? 'button-primary' : '';
					html += `<button type="button" class="button ${active}" data-page="${i}">${i}</button>`;
				} else if (i === this.currentPage - 3 || i === this.currentPage + 3) {
					html += '<span class="aebg-pagination-ellipsis">...</span>';
				}
			}

			// Next button
			if (this.currentPage < totalPages) {
				html += `<button type="button" class="button" data-page="${this.currentPage + 1}">Next</button>`;
			}

			html += '</div>';
			$pagination.html(html);

			// Bind pagination clicks
			$pagination.on('click', 'button[data-page]', function() {
				self.currentPage = parseInt($(this).data('page'));
				self.loadTemplates();
			});
		},

		openModal: function(template) {
			const $modal = $('#aebg-template-modal');
			const $form = $('#aebg-template-form');
			
			if (template) {
				// Edit mode
				$('#aebg-modal-title').text('Edit Template');
				$('#aebg-template-id').val(template.id);
				$('#aebg-template-name').val(template.name);
				$('#aebg-template-description').val(template.description || '');
				$('#aebg-template-prompt').val(template.prompt);
				$('#aebg-template-category').val(template.category || 'general');
				$('#aebg-template-is-public').prop('checked', template.is_public || false);
				$('#aebg-template-tags').val(Array.isArray(template.tags) ? template.tags.join(', ') : '');
				
				if (template.widget_types && Array.isArray(template.widget_types)) {
					$('#aebg-template-widget-types').val(template.widget_types);
				}
			} else {
				// Create mode
				$('#aebg-modal-title').text('Create Template');
				$form[0].reset();
				$('#aebg-template-id').val('');
			}

			$modal.fadeIn(200);
		},

		closeModal: function() {
			$('#aebg-template-modal').fadeOut(200);
		},

		saveTemplate: function() {
			const $form = $('#aebg-template-form');
			const formData = {
				name: $('#aebg-template-name').val(),
				description: $('#aebg-template-description').val(),
				prompt: $('#aebg-template-prompt').val(),
				category: $('#aebg-template-category').val() || 'general',
				is_public: $('#aebg-template-is-public').is(':checked'),
				tags: $('#aebg-template-tags').val().split(',').map(t => t.trim()).filter(t => t),
				widget_types: $('#aebg-template-widget-types').val() || null
			};

			if (!formData.name || !formData.prompt) {
				alert('Please fill in all required fields.');
				return;
			}

			const templateId = $('#aebg-template-id').val();
			const method = templateId ? 'PUT' : 'POST';
			const url = templateId 
				? aebgPromptTemplates.rest_url + templateId
				: aebgPromptTemplates.rest_url;

			$('#aebg-template-save-btn').prop('disabled', true).text('Saving...');

			$.ajax({
				url: url,
				method: method,
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', aebgPromptTemplates.rest_nonce);
				},
				data: JSON.stringify(formData),
				contentType: 'application/json',
				success: function(response) {
					PromptTemplatesAdmin.closeModal();
					PromptTemplatesAdmin.loadTemplates();
					PromptTemplatesAdmin.loadCategories();
				},
				error: function(xhr) {
					let errorMsg = 'Failed to save template.';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					}
					alert(errorMsg);
				},
				complete: function() {
					$('#aebg-template-save-btn').prop('disabled', false).text('Save Template');
				}
			});
		},

		editTemplate: function(templateId) {
			const template = this.templates.find(t => t.id === templateId);
			if (template) {
				this.openModal(template);
			} else {
				// Load template from API
				$.ajax({
					url: aebgPromptTemplates.rest_url + templateId,
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', aebgPromptTemplates.rest_nonce);
					},
					success: function(response) {
						PromptTemplatesAdmin.openModal(response);
					},
					error: function() {
						alert('Failed to load template.');
					}
				});
			}
		},

		duplicateTemplate: function(templateId) {
			const template = this.templates.find(t => t.id === templateId);
			if (!template) return;

			if (!confirm('Duplicate this template?')) return;

			const newTemplate = Object.assign({}, template, {
				name: template.name + ' (Copy)',
				id: null
			});

			this.openModal(newTemplate);
		},

		deleteTemplate: function(templateId) {
			if (!confirm('Are you sure you want to delete this template? This action cannot be undone.')) {
				return;
			}

			$.ajax({
				url: aebgPromptTemplates.rest_url + templateId,
				method: 'DELETE',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', aebgPromptTemplates.rest_nonce);
				},
				success: function() {
					PromptTemplatesAdmin.loadTemplates();
					PromptTemplatesAdmin.loadCategories();
				},
				error: function(xhr) {
					let errorMsg = 'Failed to delete template.';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					}
					alert(errorMsg);
				}
			});
		},

		getRelativeTime: function(dateString) {
			const date = new Date(dateString);
			const now = new Date();
			const diff = now - date;
			const seconds = Math.floor(diff / 1000);
			const minutes = Math.floor(seconds / 60);
			const hours = Math.floor(minutes / 60);
			const days = Math.floor(hours / 24);

			if (days > 0) return days + ' day' + (days > 1 ? 's' : '') + ' ago';
			if (hours > 0) return hours + ' hour' + (hours > 1 ? 's' : '') + ' ago';
			if (minutes > 0) return minutes + ' minute' + (minutes > 1 ? 's' : '') + ' ago';
			return 'just now';
		},

		escapeHtml: function(text) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return String(text).replace(/[&<>"']/g, m => map[m]);
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		PromptTemplatesAdmin.init();
	});

})(jQuery);

