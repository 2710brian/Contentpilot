/**
 * Networks Tab - Modular JavaScript
 * Class-based architecture for network management
 * 
 * @package AEBG
 */

(function() {
    'use strict';

    /**
     * Main Networks Tab Manager
     */
    class NetworksTabManager {
    constructor() {
        this.container = document.querySelector('.aebg-networks-container');
        if (!this.container) {
            return; // Not on networks tab
        }

        this.filterManager = new FilterManager(this);
        this.saveManager = new SaveManager(this);
        this.credentialsManager = new CredentialsManager(this);
        this.statsCarousel = new StatsCarousel(this);
        this.paginationManager = new PaginationManager(this);

        this.init();
    }

    init() {
        // Initialize all managers
        this.filterManager.init();
        this.saveManager.init();
        this.credentialsManager.init();
        this.statsCarousel.init();
        this.paginationManager.init();

        // Update initial filter chips
        this.filterManager.updateFilterChips();
        
        // Initialize credential toggles
        this.initCredentialToggles();
        
        // Auto-sync on first visit if needed
        if (typeof aebgShouldAutoSync !== 'undefined' && aebgShouldAutoSync) {
            this.autoSyncNetworks();
        }
    }
    
    initCredentialToggles() {
        // Handle credential panel toggles
        document.addEventListener('click', (e) => {
            if (e.target.closest('.aebg-toggle-credentials')) {
                e.preventDefault();
                const button = e.target.closest('.aebg-toggle-credentials');
                const networkKey = button.dataset.network;
                const panel = document.querySelector(`.aebg-credentials-panel[data-network="${networkKey}"]`);
                
                if (panel) {
                    const isExpanded = button.getAttribute('aria-expanded') === 'true';
                    button.setAttribute('aria-expanded', !isExpanded);
                    panel.style.display = isExpanded ? 'none' : 'block';
                }
            }
        });
    }
    
    autoSyncNetworks() {
        const statusDiv = document.getElementById('aebg-auto-save-status');
        if (statusDiv) {
            this.showNotice(statusDiv, 'Syncing networks from Datafeedr API...', 'info');
        }
        
        const formData = new FormData();
        formData.append('action', 'aebg_sync_networks_from_api');
        formData.append('nonce', document.getElementById('aebg_sync_networks_nonce')?.value || '');
        formData.append('clear_existing', 'false');
        
        fetch(this.getAjaxUrl(), {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (statusDiv) {
                    this.showNotice(statusDiv, 'Networks synced successfully! Refreshing...', 'success');
                }
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                const errorMsg = data.data?.message || 'Unknown error';
                if (statusDiv) {
                    this.showNotice(statusDiv, `Sync failed: ${errorMsg}`, 'error');
                }
            }
        })
        .catch(error => {
            console.error('Auto-sync error:', error);
            if (statusDiv) {
                this.showNotice(statusDiv, 'Error syncing networks. Please try again later.', 'error');
            }
        });
    }

        getAjaxUrl() {
            if (typeof aebg_ajax !== 'undefined' && aebg_ajax.ajaxurl) {
                return aebg_ajax.ajaxurl;
            } else if (typeof aebg !== 'undefined' && aebg.ajaxurl) {
                return aebg.ajaxurl;
            } else if (typeof ajaxurl !== 'undefined') {
                return ajaxurl;
            } else {
                return window.ajaxurl || '';
            }
        }

        getNetworkAnalytics() {
            if (typeof aebgNetworkAnalytics !== 'undefined') {
                return aebgNetworkAnalytics;
            }
            return {
                ajaxUrl: this.getAjaxUrl(),
                nonce: ''
            };
        }

        showNotice(element, message, type = 'info') {
            const noticeClass = `notice-${type}`;
            element.innerHTML = `<div class="notice ${noticeClass}"><p>${message}</p></div>`;
        }

        clearNotice(element) {
            element.innerHTML = '';
        }
    }

    /**
     * Filter Manager - Handles all filtering functionality
     */
    class FilterManager {
        constructor(parent) {
            this.parent = parent;
            this.searchInput = document.getElementById('aebg-network-search');
            this.countryFilter = document.getElementById('aebg-country-filter');
            this.regionFilter = document.getElementById('aebg-region-filter');
            this.statusFilter = document.getElementById('aebg-status-filter');
            this.clearBtn = document.getElementById('aebg-clear-filters');
            this.countDisplay = document.querySelector('.aebg-network-count');
            this.filterChipsContainer = null;
            
            // Debounce search
            this.searchTimeout = null;
        }

        init() {
            if (!this.searchInput) return;

            // Create filter chips container
            this.createFilterChipsContainer();

            // Bind events
            this.searchInput.addEventListener('input', () => this.debouncedFilter());
            if (this.countryFilter) {
                this.countryFilter.addEventListener('change', () => {
                    this.filter();
                    this.updateFilterChips();
                });
            }
            if (this.regionFilter) {
                this.regionFilter.addEventListener('change', () => {
                    this.filter();
                    this.updateFilterChips();
                });
            }
            if (this.statusFilter) {
                this.statusFilter.addEventListener('change', () => {
                    this.filter();
                    this.updateFilterChips();
                });
            }
            if (this.clearBtn) {
                this.clearBtn.addEventListener('click', () => this.clearFilters());
            }
        }

        createFilterChipsContainer() {
            const filtersBar = document.querySelector('.aebg-filters-bar');
            if (filtersBar && !this.filterChipsContainer) {
                this.filterChipsContainer = document.createElement('div');
                this.filterChipsContainer.className = 'aebg-filter-chips';
                filtersBar.appendChild(this.filterChipsContainer);
            }
        }

        debouncedFilter() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.filter();
                this.updateFilterChips();
            }, 300);
        }

        filter() {
            const searchTerm = this.searchInput ? this.searchInput.value.toLowerCase() : '';
            const selectedCountry = this.countryFilter ? this.countryFilter.value : '';
            const selectedRegion = this.regionFilter ? this.regionFilter.value : '';
            const selectedStatus = this.statusFilter ? this.statusFilter.value : '';

            let visibleCount = 0;
            let configuredCount = 0;

            const cards = document.querySelectorAll('.aebg-network-card');
            cards.forEach(card => {
                const networkName = card.querySelector('h3')?.textContent.toLowerCase() || '';
                const country = card.dataset.country || '';
                const region = card.dataset.region || '';
                const isConfigured = card.dataset.configured === '1';

                const matchesSearch = !searchTerm || networkName.includes(searchTerm);
                const matchesCountry = !selectedCountry || country === selectedCountry;
                const matchesRegion = !selectedRegion || region === selectedRegion;
                let matchesStatus = true;
                
                if (selectedStatus === 'configured') {
                    matchesStatus = isConfigured;
                } else if (selectedStatus === 'not_configured') {
                    matchesStatus = !isConfigured;
                }

                const matches = matchesSearch && matchesCountry && matchesRegion && matchesStatus;
                
                // Store visibility state in data attribute for pagination
                card.dataset.visible = matches ? '1' : '0';
                
                if (matches) {
                    visibleCount++;
                    if (isConfigured) configuredCount++;
                } else {
                    // Hide non-matching cards immediately
                    card.style.display = 'none';
                }
            });

            this.updateCount(configuredCount, visibleCount);
            
            // Reset to page 1 and trigger pagination update after filtering
            if (this.parent.paginationManager) {
                this.parent.paginationManager.currentPage = 1;
                this.parent.paginationManager.updatePagination();
            }
        }

        updateCount(configured, total) {
            if (this.countDisplay) {
                this.countDisplay.textContent = `${configured} of ${total} configured`;
            }
        }

        clearFilters() {
            if (this.searchInput) this.searchInput.value = '';
            if (this.countryFilter) this.countryFilter.value = '';
            if (this.regionFilter) this.regionFilter.value = '';
            if (this.statusFilter) this.statusFilter.value = '';
            this.filter();
            this.updateFilterChips();
        }

        updateFilterChips() {
            if (!this.filterChipsContainer) return;

            const chips = [];
            const country = this.countryFilter?.value;
            const region = this.regionFilter?.value;
            const status = this.statusFilter?.value;

            if (country) {
                chips.push({
                    label: `Country: ${country}`,
                    type: 'country',
                    value: country
                });
            }
            if (region) {
                chips.push({
                    label: `Region: ${region}`,
                    type: 'region',
                    value: region
                });
            }
            if (status) {
                const statusLabel = status === 'configured' ? 'Configured' : 'Not Configured';
                chips.push({
                    label: `Status: ${statusLabel}`,
                    type: 'status',
                    value: status
                });
            }

            if (chips.length === 0) {
                this.filterChipsContainer.innerHTML = '';
                return;
            }

            this.filterChipsContainer.innerHTML = chips.map(chip => `
                <span class="aebg-filter-chip">
                    ${chip.label}
                    <span class="dashicons dashicons-no-alt" data-filter-type="${chip.type}"></span>
                </span>
            `).join('');

            // Add click handlers to remove chips
            this.filterChipsContainer.querySelectorAll('.dashicons').forEach(icon => {
                icon.addEventListener('click', () => {
                    const type = icon.dataset.filterType;
                    if (type === 'country' && this.countryFilter) {
                        this.countryFilter.value = '';
                    } else if (type === 'region' && this.regionFilter) {
                        this.regionFilter.value = '';
                    } else if (type === 'status' && this.statusFilter) {
                        this.statusFilter.value = '';
                    }
                    this.filter();
                    this.updateFilterChips();
                });
            });
        }
    }

    /**
     * Save Manager - Handles auto-saving network IDs
     */
    class SaveManager {
        constructor(parent) {
            this.parent = parent;
            this.statusDiv = document.getElementById('aebg-auto-save-status');
            this.nonceField = document.getElementById('aebg_networks_nonce');
            this.saveTimeout = null;
            this.isSaving = false;
        }

        init() {
            // Auto-save on input change (debounced)
            const inputs = document.querySelectorAll('.aebg-affiliate-input');
            inputs.forEach(input => {
                input.addEventListener('input', () => this.debouncedSave());
                input.addEventListener('blur', () => this.saveImmediate());
            });
        }
        
        debouncedSave() {
            clearTimeout(this.saveTimeout);
            this.saveTimeout = setTimeout(() => {
                this.saveAll();
            }, 1000); // 1 second debounce
        }
        
        saveImmediate() {
            clearTimeout(this.saveTimeout);
            this.saveAll();
        }

        saveAll() {
            if (!this.nonceField) {
                console.error('Nonce field not found');
                return;
            }
            
            if (this.isSaving) {
                return; // Already saving
            }
            
            this.isSaving = true;
            
            // Show subtle saving indicator
            if (this.statusDiv) {
                this.statusDiv.innerHTML = '<div class="notice notice-info" style="margin: 0; padding: 8px 12px; font-size: 13px;"><span class="dashicons dashicons-update" style="animation: spin 1s linear infinite; font-size: 16px; width: 16px; height: 16px;"></span> Saving...</div>';
            }

            const formData = new FormData();
            formData.append('action', 'aebg_save_networks_ajax');
            formData.append('nonce', this.nonceField.value);

            const inputs = document.querySelectorAll('.aebg-affiliate-input');
            inputs.forEach(input => {
                const networkKey = input.dataset.network;
                const value = input.value;
                formData.append(`affiliate_ids[${networkKey}]`, value);
            });

            fetch(this.parent.getAjaxUrl(), {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (this.statusDiv) {
                        this.statusDiv.innerHTML = '<div class="notice notice-success" style="margin: 0; padding: 8px 12px; font-size: 13px;"><span class="dashicons dashicons-yes" style="color: #10b981;"></span> Saved</div>';
                    }
                    this.updateCardStatuses();
                    this.parent.filterManager.filter();
                    
                    // Auto-dismiss success message
                    setTimeout(() => {
                        if (this.statusDiv) {
                            this.statusDiv.innerHTML = '';
                        }
                    }, 2000);
                } else {
                    if (this.statusDiv) {
                        this.statusDiv.innerHTML = `<div class="notice notice-error" style="margin: 0; padding: 8px 12px; font-size: 13px;">Error: ${data.data || 'Unknown error'}</div>`;
                    }
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                if (this.statusDiv) {
                    this.statusDiv.innerHTML = '<div class="notice notice-error" style="margin: 0; padding: 8px 12px; font-size: 13px;">Error saving. Please try again.</div>';
                }
            })
            .finally(() => {
                this.isSaving = false;
            });
        }

        updateCardStatuses() {
            const inputs = document.querySelectorAll('.aebg-affiliate-input');
            inputs.forEach(input => {
                const card = input.closest('.aebg-network-card');
                if (!card) return;

                const statusDiv = card.querySelector('.aebg-network-status');
                if (!statusDiv) return;

                const hasValue = input.value.trim() !== '';
                card.dataset.configured = hasValue ? '1' : '0';

                if (hasValue) {
                    statusDiv.innerHTML = '<span class="aebg-status aebg-status-active"><span class="dashicons dashicons-yes"></span> Configured</span>';
                } else {
                    statusDiv.innerHTML = '<span class="aebg-status aebg-status-inactive"><span class="dashicons dashicons-no"></span> Not configured</span>';
                }
            });
        }
    }


    /**
     * Stats Slider - Handles scrollable stat cards
     */
    class StatsCarousel {
        constructor(parent) {
            this.parent = parent;
            this.wrapper = document.querySelector('.aebg-stats-carousel-wrapper');
            this.carousel = document.querySelector('.aebg-stats-carousel');
            this.statsContainer = document.querySelector('.aebg-networks-stats');
            this.prevBtn = document.querySelector('.aebg-carousel-prev');
            this.nextBtn = document.querySelector('.aebg-carousel-next');
            this.indicatorsContainer = document.querySelector('.aebg-carousel-indicators');
            
            this.currentIndex = 0;
            this.totalSlides = 0;
            this.slidesPerView = 4; // Desktop: show 4 cards
            this.isTransitioning = false;
            
            // Touch/swipe support
            this.touchStartX = 0;
            this.touchEndX = 0;
            this.minSwipeDistance = 50;
        }

        init() {
            if (!this.statsContainer || !this.carousel) return;

            const cards = this.statsContainer.querySelectorAll('.aebg-stat-card');
            this.totalSlides = cards.length;
            
            if (this.totalSlides === 0) return;

            // Determine slides per view based on screen size
            this.updateSlidesPerView();
            
            // Create indicators
            this.createIndicators();
            
            // Setup navigation buttons
            if (this.prevBtn) {
                this.prevBtn.addEventListener('click', () => this.goToPrev());
            }
            if (this.nextBtn) {
                this.nextBtn.addEventListener('click', () => this.goToNext());
            }
            
            // Touch/swipe support
            this.setupTouchEvents();
            
            // Update on window resize
            let resizeTimeout;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(() => {
                    this.updateSlidesPerView();
                    this.updateCarousel();
                }, 250);
            });
            
            // Initial update
            this.updateCarousel();
        }
        
        updateSlidesPerView() {
            const width = window.innerWidth;
            if (width <= 480) {
                this.slidesPerView = 1; // Small mobile: 1 card
            } else if (width <= 768) {
                this.slidesPerView = 1; // Mobile: 1 card
            } else if (width <= 1024) {
                this.slidesPerView = 2; // Tablet: 2 cards
            } else {
                this.slidesPerView = 4; // Desktop: 4 cards
            }
            
            // Clamp current index
            const maxIndex = Math.max(0, this.totalSlides - this.slidesPerView);
            if (this.currentIndex > maxIndex) {
                this.currentIndex = maxIndex;
            }
        }
        
        createIndicators() {
            if (!this.indicatorsContainer) return;
            
            this.indicatorsContainer.innerHTML = '';
            const totalIndicators = Math.max(1, this.totalSlides - this.slidesPerView + 1);
            
            for (let i = 0; i < totalIndicators; i++) {
                const indicator = document.createElement('button');
                indicator.type = 'button';
                indicator.className = 'aebg-carousel-indicator';
                if (i === 0) indicator.classList.add('active');
                indicator.setAttribute('aria-label', `Go to slide ${i + 1}`);
                indicator.addEventListener('click', () => this.goToSlide(i));
                this.indicatorsContainer.appendChild(indicator);
            }
        }
        
        goToSlide(index) {
            if (this.isTransitioning) return;
            
            const maxIndex = Math.max(0, this.totalSlides - this.slidesPerView);
            const newIndex = Math.max(0, Math.min(index, maxIndex));
            
            if (newIndex === this.currentIndex) return;
            
            this.currentIndex = newIndex;
            this.updateCarousel();
        }
        
        goToPrev() {
            if (this.currentIndex > 0) {
                this.goToSlide(this.currentIndex - 1);
            }
        }
        
        goToNext() {
            const maxIndex = Math.max(0, this.totalSlides - this.slidesPerView);
            if (this.currentIndex < maxIndex) {
                this.goToSlide(this.currentIndex + 1);
            }
        }
        
        updateCarousel() {
            if (!this.statsContainer || !this.wrapper) return;
            
            const maxIndex = Math.max(0, this.totalSlides - this.slidesPerView);
            const needsCarousel = maxIndex > 0;
            
            // Toggle carousel active class
            if (needsCarousel) {
                this.wrapper.classList.add('aebg-carousel-active');
            } else {
                this.wrapper.classList.remove('aebg-carousel-active');
                this.statsContainer.style.transform = 'translateX(0)';
                this.isTransitioning = false;
                return;
            }
            
            this.isTransitioning = true;
            
            // Calculate transform
            const cardWidth = this.statsContainer.querySelector('.aebg-stat-card')?.offsetWidth || 250;
            const gap = 20;
            const translateX = -(this.currentIndex * (cardWidth + gap));
            
            // Apply transform
            this.statsContainer.style.transform = `translateX(${translateX}px)`;
            
            // Update navigation buttons
            this.updateNavigationButtons();
            
            // Update indicators
            this.updateIndicators();
            
            // Reset transition flag after animation
            setTimeout(() => {
                this.isTransitioning = false;
            }, 400);
        }
        
        updateNavigationButtons() {
            const maxIndex = Math.max(0, this.totalSlides - this.slidesPerView);
            
            if (this.prevBtn) {
                this.prevBtn.disabled = this.currentIndex <= 0;
            }
            if (this.nextBtn) {
                this.nextBtn.disabled = this.currentIndex >= maxIndex;
            }
        }
        
        updateIndicators() {
            if (!this.indicatorsContainer) return;
            
            const indicators = this.indicatorsContainer.querySelectorAll('.aebg-carousel-indicator');
            const activeIndicatorIndex = this.currentIndex;
            
            indicators.forEach((indicator, index) => {
                if (index === activeIndicatorIndex) {
                    indicator.classList.add('active');
                } else {
                    indicator.classList.remove('active');
                }
            });
        }
        
        setupTouchEvents() {
            if (!this.carousel) return;
            
            this.carousel.addEventListener('touchstart', (e) => {
                this.touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });
            
            this.carousel.addEventListener('touchend', (e) => {
                this.touchEndX = e.changedTouches[0].screenX;
                this.handleSwipe();
            }, { passive: true });
        }
        
        handleSwipe() {
            const swipeDistance = this.touchStartX - this.touchEndX;
            
            if (Math.abs(swipeDistance) > this.minSwipeDistance) {
                if (swipeDistance > 0) {
                    // Swipe left - go to next
                    this.goToNext();
                } else {
                    // Swipe right - go to prev
                    this.goToPrev();
                }
            }
        }
    }

    /**
     * Pagination Manager - Handles pagination of network cards
     */
    class PaginationManager {
        constructor(parent) {
            this.parent = parent;
            this.currentPage = 1;
            this.perPage = 50; // Default
            this.wrapper = document.getElementById('aebg-pagination-wrapper');
            this.prevBtn = document.getElementById('aebg-pagination-prev');
            this.nextBtn = document.getElementById('aebg-pagination-next');
            this.pagesContainer = document.getElementById('aebg-pagination-pages');
            this.infoDisplay = document.getElementById('aebg-pagination-info');
            this.perPageFilter = document.getElementById('aebg-per-page-filter');
        }

        init() {
            if (!this.wrapper) return;

            // Load per page from localStorage or use default
            const savedPerPage = localStorage.getItem('aebg_networks_per_page');
            if (savedPerPage) {
                this.perPage = parseInt(savedPerPage, 10) || 50;
            }

            // Set per page filter value
            if (this.perPageFilter) {
                this.perPageFilter.value = this.perPage === 999999 ? 'all' : this.perPage.toString();
            }

            // Bind events
            if (this.prevBtn) {
                this.prevBtn.addEventListener('click', () => this.goToPage(this.currentPage - 1));
            }
            if (this.nextBtn) {
                this.nextBtn.addEventListener('click', () => this.goToPage(this.currentPage + 1));
            }
            if (this.perPageFilter) {
                this.perPageFilter.addEventListener('change', (e) => {
                    const value = e.target.value;
                    this.perPage = value === 'all' ? 999999 : parseInt(value, 10);
                    localStorage.setItem('aebg_networks_per_page', this.perPage.toString());
                    this.currentPage = 1; // Reset to first page
                    this.updatePagination();
                });
            }

            // Mark all cards as visible initially (if not already filtered)
            const allCards = document.querySelectorAll('.aebg-network-card');
            allCards.forEach(card => {
                if (!card.dataset.visible) {
                    card.dataset.visible = '1';
                }
            });

            // Initial pagination update
            this.updatePagination();
        }

        getVisibleCards() {
            // Get all cards that match current filters (visible = '1')
            return Array.from(document.querySelectorAll('.aebg-network-card'))
                .filter(card => {
                    // If dataset.visible is not set, assume visible (initial state)
                    const visible = card.dataset.visible;
                    return visible === '1' || (visible === undefined && card.style.display !== 'none');
                });
        }

        updatePagination() {
            const visibleCards = this.getVisibleCards();
            const totalVisible = visibleCards.length;
            
            // Hide pagination if showing all or no cards
            if (this.perPage === 999999 || totalVisible === 0) {
                this.wrapper.style.display = 'none';
                visibleCards.forEach(card => {
                    card.style.display = '';
                });
                return;
            }

            this.wrapper.style.display = 'flex';
            
            const totalPages = Math.ceil(totalVisible / this.perPage);
            
            // Clamp current page
            if (this.currentPage > totalPages) {
                this.currentPage = Math.max(1, totalPages);
            }
            if (this.currentPage < 1) {
                this.currentPage = 1;
            }

            // Calculate range
            const startIndex = (this.currentPage - 1) * this.perPage;
            const endIndex = Math.min(startIndex + this.perPage, totalVisible);

            // Show/hide cards based on pagination
            visibleCards.forEach((card, index) => {
                if (index >= startIndex && index < endIndex) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });

            // Update pagination info
            if (this.infoDisplay) {
                const start = totalVisible > 0 ? startIndex + 1 : 0;
                const end = endIndex;
                this.infoDisplay.textContent = `Showing ${start}-${end} of ${totalVisible} networks`;
            }

            // Update prev/next buttons
            if (this.prevBtn) {
                this.prevBtn.disabled = this.currentPage <= 1;
            }
            if (this.nextBtn) {
                this.nextBtn.disabled = this.currentPage >= totalPages;
            }

            // Update page numbers
            this.updatePageNumbers(totalPages);
        }

        updatePageNumbers(totalPages) {
            if (!this.pagesContainer) return;

            this.pagesContainer.innerHTML = '';

            if (totalPages <= 1) return;

            // Show max 7 page numbers
            let startPage = Math.max(1, this.currentPage - 3);
            let endPage = Math.min(totalPages, this.currentPage + 3);

            // Adjust if near start or end
            if (endPage - startPage < 6) {
                if (startPage === 1) {
                    endPage = Math.min(7, totalPages);
                } else {
                    startPage = Math.max(1, totalPages - 6);
                }
            }

            // First page
            if (startPage > 1) {
                this.addPageButton(1);
                if (startPage > 2) {
                    this.pagesContainer.appendChild(document.createTextNode('...'));
                }
            }

            // Page numbers
            for (let i = startPage; i <= endPage; i++) {
                this.addPageButton(i);
            }

            // Last page
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    this.pagesContainer.appendChild(document.createTextNode('...'));
                }
                this.addPageButton(totalPages);
            }
        }

        addPageButton(pageNum) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'aebg-pagination-page-btn';
            if (pageNum === this.currentPage) {
                button.classList.add('active');
            }
            button.textContent = pageNum;
            button.addEventListener('click', () => this.goToPage(pageNum));
            this.pagesContainer.appendChild(button);
        }

        goToPage(page) {
            if (page < 1) return;
            
            const visibleCards = this.getVisibleCards();
            const totalVisible = visibleCards.length;
            const totalPages = Math.ceil(totalVisible / this.perPage);
            
            if (page > totalPages) return;

            this.currentPage = page;
            this.updatePagination();
            
            // Scroll to top of networks view
            const networksView = document.querySelector('.aebg-networks-view');
            if (networksView) {
                networksView.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    /**
     * Credentials Manager - Handles API credentials
     */
    class CredentialsManager {
        constructor(parent) {
            this.parent = parent;
            this.analytics = parent.getNetworkAnalytics();
        }

        init() {
            // Save credentials
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('aebg-save-credentials')) {
                    e.preventDefault();
                    this.saveCredentials(e.target);
                }
            });

            // Test credentials
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('aebg-test-credentials')) {
                    e.preventDefault();
                    this.testCredentials(e.target);
                }
            });

            // Delete credentials
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('aebg-delete-credential')) {
                    e.preventDefault();
                    this.deleteCredential(e.target);
                }
            });
        }

        saveCredentials(button) {
            const networkKey = button.dataset.network;
            const messagesDiv = document.querySelector(`.aebg-credentials-messages[data-network="${networkKey}"]`);
            
            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = 'Saving...';
            
            if (messagesDiv) {
                this.parent.showNotice(messagesDiv, 'Saving credentials...', 'info');
            }

            const credentials = {};
            const inputs = document.querySelectorAll(`.aebg-credential-input[data-network="${networkKey}"]`);
            inputs.forEach(input => {
                const credType = input.dataset.credentialType;
                const value = input.value.trim();
                if (value && value !== '••••••••') {
                    credentials[credType] = value;
                }
            });

            const formData = new FormData();
            formData.append('action', 'aebg_save_network_credentials');
            formData.append('nonce', this.analytics.nonce);
            formData.append('network_key', networkKey);
            formData.append('credentials', JSON.stringify(credentials));

            fetch(this.analytics.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (messagesDiv) {
                        this.parent.showNotice(messagesDiv, data.data.message || 'Credentials saved successfully', 'success');
                    }
                    // Update input values to show masked
                    inputs.forEach(input => {
                        if (input.value && input.value !== '••••••••') {
                            input.value = '••••••••';
                            const statusSpan = input.closest('.aebg-form-group')?.querySelector('.credential-status');
                            if (statusSpan) {
                                statusSpan.className = 'credential-status success';
                                statusSpan.textContent = '✓ Configured';
                            }
                        }
                    });
                } else {
                    if (messagesDiv) {
                        this.parent.showNotice(messagesDiv, data.data?.message || 'Failed to save credentials', 'error');
                    }
                }
            })
            .catch(error => {
                console.error('Save credentials error:', error);
                if (messagesDiv) {
                    this.parent.showNotice(messagesDiv, 'Error saving credentials. Please try again.', 'error');
                }
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = originalText;
            });
        }

        testCredentials(button) {
            const networkKey = button.dataset.network;
            const messagesDiv = document.querySelector(`.aebg-credentials-messages[data-network="${networkKey}"]`);
            
            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = 'Testing...';
            
            if (messagesDiv) {
                this.parent.showNotice(messagesDiv, 'Testing connection...', 'info');
            }

            const formData = new FormData();
            formData.append('action', 'aebg_test_network_credentials');
            formData.append('nonce', this.analytics.nonce);
            formData.append('network_key', networkKey);

            fetch(this.analytics.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (messagesDiv) {
                        this.parent.showNotice(messagesDiv, data.data.message || 'Connection test successful', 'success');
                    }
                } else {
                    if (messagesDiv) {
                        this.parent.showNotice(messagesDiv, data.data?.message || 'Connection test failed', 'error');
                    }
                }
            })
            .catch(error => {
                console.error('Test credentials error:', error);
                if (messagesDiv) {
                    this.parent.showNotice(messagesDiv, 'Error testing connection. Please try again.', 'error');
                }
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = originalText;
            });
        }

        deleteCredential(button) {
            if (!confirm('Are you sure you want to delete this credential?')) {
                return;
            }

            const networkKey = button.dataset.network;
            const credType = button.dataset.credentialType;
            const input = document.querySelector(`.aebg-credential-input[data-network="${networkKey}"][data-credential-type="${credType}"]`);
            const messagesDiv = document.querySelector(`.aebg-credentials-messages[data-network="${networkKey}"]`);
            
            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = 'Deleting...';

            const formData = new FormData();
            formData.append('action', 'aebg_delete_network_credential');
            formData.append('nonce', this.analytics.nonce);
            formData.append('network_key', networkKey);
            formData.append('credential_type', credType);

            fetch(this.analytics.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (input) {
                        input.value = '';
                        const statusSpan = input.closest('.aebg-form-group')?.querySelector('.credential-status');
                        if (statusSpan) {
                            statusSpan.className = 'credential-status missing';
                            statusSpan.textContent = 'Missing';
                        }
                    }
                    button.remove();
                    if (messagesDiv) {
                        this.parent.showNotice(messagesDiv, 'Credential deleted successfully', 'success');
                    }
                } else {
                    if (messagesDiv) {
                        this.parent.showNotice(messagesDiv, data.data?.message || 'Failed to delete credential', 'error');
                    }
                    button.disabled = false;
                    button.textContent = originalText;
                }
            })
            .catch(error => {
                console.error('Delete credential error:', error);
                if (messagesDiv) {
                    this.parent.showNotice(messagesDiv, 'Error deleting credential. Please try again.', 'error');
                }
                button.disabled = false;
                button.textContent = originalText;
            });
        }
    }

    // Initialize when DOM is ready
    function initNetworksTab() {
        // Check if we're on the networks tab
        const networksTab = document.querySelector('#aebg-tab-networks');
        const isActive = networksTab && networksTab.classList.contains('active');
        const isHashActive = window.location.hash === '#networks';
        
        if (!isActive && !isHashActive) {
            return; // Not on networks tab
        }

        // Initialize manager if not already initialized
        if (!window.NetworksPage) {
            window.NetworksPage = new NetworksTabManager();
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initNetworksTab();
            // Also check after a short delay in case tabs initialize later
            setTimeout(initNetworksTab, 500);
        });
    } else {
        initNetworksTab();
        setTimeout(initNetworksTab, 500);
    }

    // Also initialize when tab is activated (for lazy loading)
    document.addEventListener('click', (e) => {
        if (e.target.closest('.aebg-tab-btn[data-tab="networks"]')) {
            setTimeout(() => {
                if (!window.NetworksPage) {
                    window.NetworksPage = new NetworksTabManager();
                } else if (window.NetworksPage.filterManager) {
                    // Re-initialize if needed
                    window.NetworksPage.filterManager.updateFilterChips();
                }
            }, 100);
        }
    });

    // Listen for hash changes (when navigating via URL hash)
    window.addEventListener('hashchange', () => {
        if (window.location.hash === '#networks') {
            setTimeout(() => {
                if (!window.NetworksPage) {
                    window.NetworksPage = new NetworksTabManager();
                }
            }, 100);
        }
    });

})();

