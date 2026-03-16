/**
 * Modern Networks Selector for AEBG Settings Page
 * Clean, user-friendly interface for selecting networks for bulk generation
 * Includes configured networks detection and better UX
 */
class ModernNetworksSelector {
    constructor() {
        this.networks = [];
        this.selectedNetworks = new Set();
        this.filteredNetworks = [];
        this.currentFilter = 'all';
        this.searchTerm = '';
        this.configuredNetworks = new Set();
        this.init();
    }

    init() {
        try {
            console.log('Modern Networks Selector: Initializing...');
            this.loadNetworksData();
            this.detectConfiguredNetworks();
            this.bindEvents();
            this.loadExistingSelections();
            this.render();
            this.updateCounters();
            console.log('Modern Networks Selector: Initialization complete');
        } catch (error) {
            console.error('Modern Networks Selector: Error during initialization:', error);
            // Show error message to user
            this.showErrorMessage('Failed to initialize networks selector. Please refresh the page.');
        }
    }

    loadNetworksData() {
        console.log('Modern Networks Selector: Loading networks data...');
        console.log('Modern Networks Selector: aebgNetworksData type:', typeof aebgNetworksData);
        console.log('Modern Networks Selector: aebgNetworksData value:', aebgNetworksData);
        
        // Check if PHP data is available and valid
        if (typeof aebgNetworksData !== 'undefined' && aebgNetworksData) {
            console.log('Modern Networks Selector: Raw PHP data:', aebgNetworksData);
            
            // Handle different data formats
            if (Array.isArray(aebgNetworksData)) {
                // Direct array format
                this.networks = aebgNetworksData;
                console.log('Modern Networks Selector: Loaded', this.networks.length, 'networks from PHP array data');
            } else if (typeof aebgNetworksData === 'object' && aebgNetworksData !== null) {
                // Object format - convert to array
                this.networks = Object.values(aebgNetworksData);
                console.log('Modern Networks Selector: Converted object to array, loaded', this.networks.length, 'networks');
                    } else {
            console.warn('Modern Networks Selector: PHP data format not recognized');
            this.showErrorMessage('Networks data format not recognized. Please check the page configuration.');
        }
        } else {
            console.warn('Modern Networks Selector: aebgNetworksData not available');
            this.showErrorMessage('No networks data available. Please check the page configuration.');
        }
        
        // Ensure networks is always an array and has required properties
        if (!Array.isArray(this.networks)) {
            console.error('Modern Networks Selector: Networks data is not an array');
            this.showErrorMessage('Networks data is not in the expected format. Please check the page configuration.');
            return;
        }
        
        // Validate and normalize network objects
        this.networks = this.networks.map(network => {
            if (typeof network === 'object' && network !== null) {
                return {
                    code: network.code || network.key || 'unknown',
                    name: network.name || network.title || 'Unknown Network',
                    country: network.country || 'GL',
                    countryName: network.countryName || network.country || 'Global',
                    popular: network.popular || false,
                    category: network.category || 'affiliate',
                    configured: network.configured || false,
                    affiliate_id: network.affiliate_id || null
                };
            } else {
                console.warn('Modern Networks Selector: Invalid network object:', network);
                return null;
            }
        }).filter(network => network !== null);
        
        // Update configured networks set based on the loaded data
        console.log('Modern Networks Selector: Populating configured networks set...');
        this.networks.forEach(network => {
            console.log('Modern Networks Selector: Checking network', network.code, 'configured:', network.configured);
            if (network.configured) {
                this.configuredNetworks.add(network.code);
                console.log('Modern Networks Selector: Added', network.code, 'to configured set');
            }
        });
        
        console.log('Modern Networks Selector: Final configured networks set:', this.configuredNetworks);
        console.log('Modern Networks Selector: Final networks data:', this.networks);
        console.log('Modern Networks Selector: Configured networks:', Array.from(this.configuredNetworks));
        this.filteredNetworks = [...this.networks];
    }

    detectConfiguredNetworks() {
        // This method is now handled in loadNetworksData since we're getting the data directly from PHP
        // Keep it for backward compatibility but it's no longer needed
        console.log('Modern Networks Selector: Configuration detection handled in loadNetworksData');
    }

    // extractNetworkCode method removed - no longer needed

    // Fallback method removed - we want to use PHP data only

    showErrorMessage(message) {
        const container = document.querySelector('.aebg-network-selection');
        if (container) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'aebg-error-message';
            errorDiv.style.cssText = 'background: #fee2e2; color: #991b1b; padding: 15px; border: 1px solid #fecaca; border-radius: 6px; margin: 10px 0; text-align: center;';
            errorDiv.innerHTML = `<strong>Error:</strong> ${message}`;
            container.appendChild(errorDiv);
        }
    }

    bindEvents() {
        // Search functionality
        const searchInput = document.getElementById('aebg_networks_search');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchTerm = e.target.value.toLowerCase();
                this.filterNetworks();
                this.render();
            });
        }

        // Filter tabs
        const filterTabs = document.querySelectorAll('.aebg-filter-tab');
        filterTabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                const filter = e.currentTarget.dataset.filter;
                this.setActiveFilter(filter);
            });
        });

        // Action buttons
        const selectConfiguredBtn = document.getElementById('aebg_select_configured');
        if (selectConfiguredBtn) {
            selectConfiguredBtn.addEventListener('click', () => this.selectConfiguredNetworks());
        }

        const selectPopularBtn = document.getElementById('aebg_select_popular');
        if (selectPopularBtn) {
            selectPopularBtn.addEventListener('click', () => this.selectPopularNetworks());
        }

        const clearAllBtn = document.getElementById('aebg_clear_all');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', () => this.clearAllNetworks());
        }

        // Configuration checkbox
        const configuredOnlyCheckbox = document.getElementById('aebg_search_configured_only');
        if (configuredOnlyCheckbox) {
            configuredOnlyCheckbox.addEventListener('change', (e) => {
                this.handleConfiguredOnlyChange(e.target.checked);
            });
        }
    }

    setActiveFilter(filter) {
        this.currentFilter = filter;
        
        // Update active state of filter tabs
        const filterTabs = document.querySelectorAll('.aebg-filter-tab');
        filterTabs.forEach(tab => {
            tab.classList.remove('active');
            if (tab.dataset.filter === filter) {
                tab.classList.add('active');
            }
        });
        
        // Filter and render networks
        this.filterNetworks();
        this.render();
    }

    filterNetworks() {
        console.log('Modern Networks Selector: Filtering networks with filter:', this.currentFilter);
        console.log('Modern Networks Selector: Total networks:', this.networks.length);
        console.log('Modern Networks Selector: Configured networks set:', this.configuredNetworks);
        console.log('Modern Networks Selector: Sample network:', this.networks[0]);
        
        this.filteredNetworks = this.networks.filter(network => {
            // Apply category filter
            if (this.currentFilter !== 'all') {
                if (this.currentFilter === 'popular' && !network.popular) {
                    return false;
                }
                if (this.currentFilter === 'configured' && !this.configuredNetworks.has(network.code)) {
                    console.log('Modern Networks Selector: Filtering out network', network.code, 'because it\'s not in configured set');
                    return false;
                }
                if (this.currentFilter === 'amazon' && network.category !== 'amazon') {
                    return false;
                }
            }
            
            // Apply search filter
            if (this.searchTerm) {
                const searchText = `${network.name} ${network.countryName} ${network.code}`.toLowerCase();
                return searchText.includes(this.searchTerm);
            }
            
            return true;
        });
        
        console.log('Modern Networks Selector: Filtered networks count:', this.filteredNetworks.length);
    }

    render() {
        this.renderNetworksGrid();
        this.updateCounters();
    }

    renderNetworksGrid() {
        const grid = document.getElementById('aebg_networks_grid');
        if (!grid) return;

        console.log('Modern Networks Selector: Rendering networks grid...');
        console.log('Modern Networks Selector: Selected networks:', Array.from(this.selectedNetworks));
        console.log('Modern Networks Selector: Filtered networks count:', this.filteredNetworks.length);

        if (this.filteredNetworks.length === 0) {
            grid.innerHTML = `
                <div class="aebg-no-networks">
                    ${this.searchTerm ? `No networks found matching "${this.searchTerm}"` : 'No networks available'}
                </div>
            `;
            return;
        }

        grid.innerHTML = this.filteredNetworks.map(network => {
            const isSelected = this.selectedNetworks.has(network.code);
            const isConfigured = this.configuredNetworks.has(network.code);
            const statusClass = isConfigured ? 'configured' : 'unconfigured';
            const statusText = isConfigured ? 'Configured' : 'Not Configured';
            
            if (isSelected) {
                console.log('Modern Networks Selector: Network', network.code, 'is marked as selected');
            }
            
            return `
                <div class="aebg-network-item ${isSelected ? 'selected' : ''} ${isConfigured ? 'configured' : ''}" 
                     data-network-code="${network.code}">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_${network.code}" 
                               ${isSelected ? 'checked' : ''}>
                        <label for="network_${network.code}"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">${network.name}</div>
                        <div class="aebg-network-country">
                            ${network.flag || this.getCountryFlag(network.country)} ${network.countryName}
                            ${network.popular ? '<span style="color: #f59e0b; margin-left: 4px;">⭐</span>' : ''}
                        </div>
                        <div class="aebg-network-status ${statusClass}">${statusText}</div>
                    </div>
                </div>
            `;
        }).join('');

        // Add click events to network items
        const networkItems = grid.querySelectorAll('.aebg-network-item');
        networkItems.forEach(item => {
            item.addEventListener('click', (e) => {
                // Don't trigger if clicking on checkbox
                if (e.target.type === 'checkbox' || e.target.tagName === 'LABEL') {
                    return;
                }
                
                const networkCode = item.dataset.networkCode;
                this.toggleNetwork(networkCode);
                this.render();
            });
        });

        // Add change events to checkboxes
        const checkboxes = grid.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const networkCode = e.target.id.replace('network_', '');
                if (e.target.checked) {
                    this.selectedNetworks.add(networkCode);
                } else {
                    this.selectedNetworks.delete(networkCode);
                }
                this.updateHiddenInput();
                this.render();
            });
        });
    }

    toggleNetwork(networkCode) {
        if (this.selectedNetworks.has(networkCode)) {
            this.selectedNetworks.delete(networkCode);
        } else {
            this.selectedNetworks.add(networkCode);
        }
        this.updateHiddenInput();
    }

    updateHiddenInput() {
        const hiddenInput = document.getElementById('aebg_default_networks');
        if (hiddenInput) {
            const newValue = JSON.stringify(Array.from(this.selectedNetworks));
            if (hiddenInput.value !== newValue) {
                hiddenInput.value = newValue;
                // Trigger change event to notify auto-save
                const event = new Event('change', { bubbles: true });
                hiddenInput.dispatchEvent(event);
                console.log('Modern Networks Selector: Hidden input updated and change event triggered');
            }
        }
    }

    updateCounters() {
        // Update selected networks counter
        const selectedCounter = document.getElementById('aebg_selected_count');
        if (selectedCounter) {
            selectedCounter.textContent = this.selectedNetworks.size;
        }

        // Update configured networks counter
        const configuredCounter = document.getElementById('aebg_configured_count');
        if (configuredCounter) {
            configuredCounter.textContent = this.configuredNetworks.size;
        }
    }

    selectConfiguredNetworks() {
        this.configuredNetworks.forEach(code => {
            this.selectedNetworks.add(code);
        });
        this.updateHiddenInput();
        this.render();
    }

    selectPopularNetworks() {
        const popularNetworks = this.networks.filter(network => network.popular);
        popularNetworks.forEach(network => {
            this.selectedNetworks.add(network.code);
        });
        this.updateHiddenInput();
        this.render();
    }

    clearAllNetworks() {
        this.selectedNetworks.clear();
        this.updateHiddenInput();
        this.render();
    }

    handleConfiguredOnlyChange(checked) {
        if (checked) {
            // If "search only configured" is checked, select all configured networks
            this.selectConfiguredNetworks();
        }
        // Update the UI to reflect the change
        this.render();
    }

    loadExistingSelections() {
        const hiddenInput = document.getElementById('aebg_default_networks');
        console.log('Modern Networks Selector: Loading existing selections...');
        console.log('Modern Networks Selector: Hidden input found:', !!hiddenInput);
        if (hiddenInput) {
            console.log('Modern Networks Selector: Hidden input value:', hiddenInput.value);
            console.log('Modern Networks Selector: Hidden input value type:', typeof hiddenInput.value);
            console.log('Modern Networks Selector: Hidden input name:', hiddenInput.name);
            console.log('Modern Networks Selector: Hidden input id:', hiddenInput.id);
            console.log('Modern Networks Selector: Raw HTML value attribute:', hiddenInput.getAttribute('value'));
            
            // Check if the value is actually empty or just whitespace
            const trimmedValue = hiddenInput.value.trim();
            console.log('Modern Networks Selector: Trimmed value:', trimmedValue);
            console.log('Modern Networks Selector: Value length:', hiddenInput.value.length);
            console.log('Modern Networks Selector: Trimmed value length:', trimmedValue.length);
            
            if (trimmedValue && trimmedValue !== '[]') {
                try {
                    const existingSelections = JSON.parse(trimmedValue);
                    console.log('Modern Networks Selector: Parsed existing selections:', existingSelections);
                    if (Array.isArray(existingSelections) && existingSelections.length > 0) {
                        existingSelections.forEach(code => {
                            this.selectedNetworks.add(code);
                            console.log('Modern Networks Selector: Added network to selection:', code);
                        });
                        console.log('Modern Networks Selector: Final selected networks:', Array.from(this.selectedNetworks));
                    } else {
                        console.log('Modern Networks Selector: Existing selections is empty array or not an array:', existingSelections);
                    }
                } catch (error) {
                    console.warn('Modern Networks Selector: Could not parse existing selections:', error);
                    console.warn('Modern Networks Selector: Raw value that failed to parse:', trimmedValue);
                }
            } else {
                console.log('Modern Networks Selector: Hidden input has no value or empty array value');
            }
        } else {
            console.error('Modern Networks Selector: Hidden input element not found');
        }
    }

    getCountryFlag(countryCode) {
        const flagMap = {
            'US': '🇺🇸', 'UK': '🇬🇧', 'DE': '🇩🇪', 'FR': '🇫🇷', 'IT': '🇮🇹', 'ES': '🇪🇸',
            'CA': '🇨🇦', 'AU': '🇦🇺', 'JP': '🇯🇵', 'BR': '🇧🇷', 'IN': '🇮🇳', 'MX': '🇲🇽',
            'DK': '🇩🇰', 'SE': '🇸🇪', 'NO': '🇳🇴', 'FI': '🇫🇮', 'NL': '🇳🇱', 'BE': '🇧🇪',
            'GL': '🌍'
        };
        return flagMap[countryCode] || '🌍';
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Add a small delay to ensure PHP has set all the values
    setTimeout(() => {
        console.log('Modern Networks Selector: Initializing with delay...');
        new ModernNetworksSelector();
    }, 100);
}); 