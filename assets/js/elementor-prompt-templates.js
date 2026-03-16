/**
 * Elementor Prompt Templates Integration
 * 
 * Handles template library integration in Elementor editor
 */

(function($) {
	'use strict';

	const ElementorPromptTemplates = {
		restUrl: '',
		restNonce: '',
		currentWidgetId: null,
		templates: [],

		init: function() {
			// Wait for Elementor to be ready
			if (typeof elementor === 'undefined') {
				setTimeout(() => this.init(), 100);
				return;
			}

			// Get REST API URL and nonce from localized script
			if (typeof aebgPromptTemplates !== 'undefined') {
				this.restUrl = aebgPromptTemplates.rest_url;
				this.restNonce = aebgPromptTemplates.rest_nonce;
			} else {
				// Fallback: construct from WordPress
				if (typeof ajaxurl !== 'undefined') {
					this.restUrl = ajaxurl.replace('admin-ajax.php', 'wp-json/aebg/v1/prompt-templates/');
				} else {
					// Last resort: construct from current page
					const currentUrl = window.location.href;
					const baseUrl = currentUrl.split('/wp-admin/')[0] || currentUrl.split('/wp-json/')[0];
					this.restUrl = baseUrl + '/wp-json/aebg/v1/prompt-templates/';
				}
				this.restNonce = $('input[name="_wpnonce"]').val() || $('meta[name="wp-api-nonce"]').attr('content') || '';
			}

			// Ensure URL ends with /
			if (this.restUrl && !this.restUrl.endsWith('/')) {
				this.restUrl += '/';
			}

			this.bindEvents();
			this.createModal();
		},

		bindEvents: function() {
			const self = this;

			// Template library link clicks
			$(document).on('click', '.aebg-open-template-library-link', function(e) {
				e.preventDefault();
				e.stopPropagation();
				const widgetId = self.getCurrentWidgetId();
				self.openTemplateLibrary(widgetId);
			});

			// Save as template link clicks
			$(document).on('click', '.aebg-save-as-template-link', function(e) {
				e.preventDefault();
				e.stopPropagation();
				const widgetId = self.getCurrentWidgetId();
				self.saveAsTemplate(widgetId);
			});

		},


		getCurrentWidgetId: function() {
			if (typeof elementor !== 'undefined') {
				// Try to get from current editor
				if (elementor.panels && elementor.panels.currentView) {
					const currentView = elementor.panels.currentView;
					if (currentView.model && currentView.model.id) {
						return currentView.model.id;
					}
				}
				
				// Try to get from editor
				if (elementor.getCurrentElement && elementor.getCurrentElement()) {
					const element = elementor.getCurrentElement();
					if (element && element.model && element.model.id) {
						return element.model.id;
					}
				}
				
				// Try to get from selection
				if (elementor.selection && elementor.selection.getSelected) {
					const selected = elementor.selection.getSelected();
					if (selected && selected.length > 0 && selected[0].id) {
						return selected[0].id;
					}
				}
			}
			return null;
		},

		createModal: function() {
			// Create modal HTML if it doesn't exist
			if ($('#aebg-elementor-template-modal').length === 0) {
				const modalHtml = `
					<div id="aebg-elementor-template-modal" class="aebg-elementor-modal" style="display: none;">
						<div class="aebg-elementor-modal-overlay"></div>
						<div class="aebg-elementor-modal-content">
							<div class="aebg-elementor-modal-header">
								<h2>Template Library</h2>
								<button type="button" class="aebg-elementor-modal-close">
									<span class="dashicons dashicons-no-alt"></span>
								</button>
							</div>
							<div class="aebg-elementor-modal-body">
								<div class="aebg-elementor-modal-search">
									<input type="search" id="aebg-elementor-template-search" 
									       placeholder="Search templates...">
									<select id="aebg-elementor-template-category">
										<option value="">All Categories</option>
									</select>
								</div>
								<div id="aebg-elementor-templates-list" class="aebg-elementor-templates-list">
									<div class="aebg-loading">Loading templates...</div>
								</div>
							</div>
						</div>
					</div>
				`;
				$('body').append(modalHtml);

				// Bind modal events
				$('.aebg-elementor-modal-close, .aebg-elementor-modal-overlay').on('click', () => {
					this.closeModal();
				});

				// Search
				let searchTimeout;
				$('#aebg-elementor-template-search').on('input', function() {
					clearTimeout(searchTimeout);
					searchTimeout = setTimeout(() => {
						ElementorPromptTemplates.loadTemplates($(this).val());
					}, 300);
				});

				// Category filter
				$('#aebg-elementor-template-category').on('change', function() {
					ElementorPromptTemplates.loadTemplates(
						$('#aebg-elementor-template-search').val(),
						$(this).val()
					);
				});
			}
		},

		openTemplateLibrary: function(widgetId) {
			this.currentWidgetId = widgetId;
			this.loadTemplates();
			this.loadCategories();
			$('#aebg-elementor-template-modal').fadeIn(200);
		},

		closeModal: function() {
			$('#aebg-elementor-template-modal').fadeOut(200);
		},

		loadTemplates: function(search = '', category = '') {
			const self = this;
			const $list = $('#aebg-elementor-templates-list');
			
			$list.html('<div class="aebg-loading">Loading templates...</div>');

			const params = {
				per_page: 50,
				page: 1
			};

			if (search) {
				params.search = search;
			}

			if (category) {
				params.category = category;
			}

			$.ajax({
				url: this.restUrl,
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', self.restNonce);
				},
				data: params,
				success: function(response) {
					self.templates = Array.isArray(response) ? response : [];
					self.renderTemplates();
				},
				error: function(xhr) {
					let errorMsg = 'Failed to load templates.';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					} else if (xhr.status === 500) {
						errorMsg = 'Server error. The templates table may not exist. Please deactivate and reactivate the plugin.';
					} else if (xhr.status === 0) {
						errorMsg = 'Network error. Please check your connection and try again.';
					}
					$list.html('<div class="aebg-error">' + errorMsg + '</div>');
					console.error('Templates load error:', xhr);
				}
			});
		},

		loadCategories: function() {
			const self = this;
			$.ajax({
				url: this.restUrl + 'categories',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', self.restNonce);
				},
				success: function(response) {
					const categories = Array.isArray(response) ? response : [];
					let html = '<option value="">All Categories</option>';
					categories.forEach(function(cat) {
						html += `<option value="${self.escapeHtml(cat)}">${self.escapeHtml(cat)}</option>`;
					});
					$('#aebg-elementor-template-category').html(html);
				}
			});
		},

		renderTemplates: function() {
			const $list = $('#aebg-elementor-templates-list');
			
			if (this.templates.length === 0) {
				$list.html('<div class="aebg-empty-state">No templates found.</div>');
				return;
			}

			let html = '<div class="aebg-elementor-templates-grid">';
			this.templates.forEach((template) => {
				html += this.renderTemplateItem(template);
			});
			html += '</div>';

			$list.html(html);

			// Bind template selection
			$list.on('click', '.aebg-elementor-template-item', (e) => {
				const templateId = $(e.currentTarget).data('template-id');
				this.selectTemplate(templateId);
			});
		},

		renderTemplateItem: function(template) {
			const promptPreview = template.prompt.length > 100 
				? template.prompt.substring(0, 100) + '...' 
				: template.prompt;

			return `
				<div class="aebg-elementor-template-item" data-template-id="${template.id}">
					<div class="aebg-elementor-template-item-header">
						<h4>${this.escapeHtml(template.name)}</h4>
						${template.is_public ? '<span class="aebg-badge-public">Public</span>' : ''}
					</div>
					${template.description ? `<p class="aebg-elementor-template-item-desc">${this.escapeHtml(template.description)}</p>` : ''}
					<div class="aebg-elementor-template-item-preview">
						${this.escapeHtml(promptPreview)}
					</div>
					<div class="aebg-elementor-template-item-footer">
						<span class="aebg-elementor-template-category">${this.escapeHtml(template.category || 'general')}</span>
						<span class="aebg-elementor-template-usage">Used ${template.usage_count || 0} times</span>
					</div>
				</div>
			`;
		},

		selectTemplate: function(templateId) {
			const self = this;
			const template = this.templates.find(t => t.id === templateId);
			
			// Show confirmation dialog
			const confirmMessage = 'Do you want to use this template? This will replace your current prompt.';
			if (!confirm(confirmMessage)) {
				return; // User cancelled
			}
			
			if (!template) {
				// Load template from API
				$.ajax({
					url: this.restUrl + templateId,
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', self.restNonce);
					},
					success: function(response) {
						if (response && response.prompt) {
							self.applyTemplate(response);
						} else {
							alert('Template data is invalid. Please try again.');
						}
					},
					error: function(xhr) {
						let errorMsg = 'Failed to load template.';
						if (xhr.responseJSON && xhr.responseJSON.message) {
							errorMsg = xhr.responseJSON.message;
						} else if (xhr.status === 404) {
							errorMsg = 'Template not found.';
						} else if (xhr.status === 500) {
							errorMsg = 'Server error. The templates table may not exist. Please deactivate and reactivate the plugin.';
						}
						alert(errorMsg);
						console.error('Template load error:', xhr);
					}
				});
			} else {
				this.applyTemplate(template);
			}
		},

		applyTemplate: function(template) {
			const self = this;
			
			// Update Elementor control value using multiple methods for reliability
			if (typeof elementor !== 'undefined') {
				let updated = false;
				
				// Method 1: Update via panel view model (most reliable)
				if (elementor.panels && elementor.panels.currentView) {
					const currentView = elementor.panels.currentView;
					if (currentView.model) {
						currentView.model.setSetting('aebg_ai_prompt', template.prompt);
						updated = true;
					}
				}
				
				// Method 2: Update via editor's current element
				if (elementor.getEditor) {
					const editor = elementor.getEditor();
					if (editor) {
						// Try getCurrentElement method
						if (editor.getCurrentElement) {
							const element = editor.getCurrentElement();
							if (element && element.model) {
								element.model.setSetting('aebg_ai_prompt', template.prompt);
								updated = true;
							}
						}
						
						// Try getCurrentPage method
						if (editor.getCurrentPage) {
							const page = editor.getCurrentPage();
							if (page && page.getSelectedElement) {
								const element = page.getSelectedElement();
								if (element && element.model) {
									element.model.setSetting('aebg_ai_prompt', template.prompt);
									updated = true;
								}
							}
						}
					}
				}
				
				// Method 3: Direct DOM update and trigger Elementor events
				const $promptControl = $('.elementor-control-aebg_ai_prompt textarea');
				if ($promptControl.length > 0) {
					$promptControl.val(template.prompt);
					
					// Trigger native events
					$promptControl.trigger('input');
					$promptControl.trigger('change');
					
					// Trigger Elementor-specific events
					if (elementor.channels && elementor.channels.editor) {
						elementor.channels.editor.trigger('element:settings:changed');
					}
					
					// Trigger via jQuery on Elementor's control
					$promptControl.closest('.elementor-control').trigger('input');
					
					updated = true;
				}
				
				// Method 4: Use Elementor's hooks to update
				if (elementor.hooks) {
					elementor.hooks.doAction('panel/open_editor/widget');
				}
			}

			// Track usage
			$.ajax({
				url: this.restUrl + template.id + '/usage',
				method: 'POST',
				beforeSend: (xhr) => {
					xhr.setRequestHeader('X-WP-Nonce', this.restNonce);
				}
			});

			// Close modal
			this.closeModal();

			// Show success message
			if (typeof elementor !== 'undefined' && elementor.notifications) {
				elementor.notifications.showToast({
					message: 'Template loaded successfully!',
					icon: 'eicon-check-circle',
				});
			}
		},

		saveAsTemplate: function(widgetId) {
			const self = this;
			
			// Get current prompt value using multiple methods
			let currentPrompt = '';
			
			// Method 1: Get from DOM textarea (most reliable)
			const $promptControl = $('.elementor-control-aebg_ai_prompt textarea');
			if ($promptControl.length > 0) {
				currentPrompt = $promptControl.val() || '';
			}
			
			// Method 2: Get from Elementor panel view model
			if (!currentPrompt && typeof elementor !== 'undefined' && elementor.panels && elementor.panels.currentView) {
				const currentView = elementor.panels.currentView;
				if (currentView.model) {
					currentPrompt = currentView.model.getSetting('aebg_ai_prompt') || '';
				}
			}
			
			// Method 3: Get from editor's current element
			if (!currentPrompt && typeof elementor !== 'undefined') {
				if (elementor.getEditor) {
					const editor = elementor.getEditor();
					if (editor && editor.getCurrentElement) {
						const element = editor.getCurrentElement();
						if (element && element.model) {
							currentPrompt = element.model.getSetting('aebg_ai_prompt') || '';
						}
					}
				}
				
				// Method 4: Try getCurrentElement directly
				if (!currentPrompt && elementor.getCurrentElement) {
					const element = elementor.getCurrentElement();
					if (element && element.getSettings) {
						const settings = element.getSettings();
						currentPrompt = settings.aebg_ai_prompt || '';
					}
				}
			}
			
			// Trim whitespace
			currentPrompt = currentPrompt ? currentPrompt.trim() : '';

			if (!currentPrompt) {
				alert('Please enter a prompt first before saving as template.');
				return;
			}

			// Prompt for template name
			const templateName = prompt('Enter a name for this template:');
			if (!templateName) {
				return;
			}

			// Create template
			const templateData = {
				name: templateName,
				prompt: currentPrompt,
				category: 'general',
				is_public: false
			};

			$.ajax({
				url: this.restUrl,
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', self.restNonce);
				},
				data: JSON.stringify(templateData),
				contentType: 'application/json',
				success: function(response) {
					if (typeof elementor !== 'undefined' && elementor.notifications) {
						elementor.notifications.showToast({
							message: 'Template saved successfully!',
							icon: 'eicon-check-circle',
						});
					}
				},
				error: function(xhr) {
					let errorMsg = 'Failed to save template.';
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errorMsg = xhr.responseJSON.message;
					}
					alert(errorMsg);
				}
			});
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

	// Initialize when DOM is ready
	$(document).ready(function() {
		ElementorPromptTemplates.init();
	});

	// Also initialize when Elementor is ready
	if (typeof elementor !== 'undefined') {
		elementor.hooks.addAction('panel/open_editor/widget', function() {
			ElementorPromptTemplates.init();
		});
	}

})(jQuery);

