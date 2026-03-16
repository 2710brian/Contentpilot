/**
 * Modern Network Selector JavaScript
 * 
 * This file provides the interactive functionality for the modern network selector
 * interface, replacing the old multi-select dropdown.
 */

jQuery(document).ready(function($) {
    class ModernNetworkSelector {
        constructor() {
            this.selectedNetworks = new Set();
            this.allNetworks = this.getAllNetworks();
            this.init();
        }

        init() {
            this.bindEvents();
            this.populateAllNetworksTab();
            this.updateSelectedNetworksDisplay();
        }

        bindEvents() {
            // Tab switching
            $(document).on('click', '.aebg-tab-button', (e) => {
                this.switchTab($(e.currentTarget));
            });

            // Network selection
            $(document).on('change', '.aebg-network-checkbox input[type="checkbox"]', (e) => {
                this.handleNetworkSelection(e);
            });

            // Network item click (for better UX)
            $(document).on('click', '.aebg-network-item', (e) => {
                if (!$(e.target).is('input, label')) {
                    const checkbox = $(e.currentTarget).find('input[type="checkbox"]');
                    checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
                }
            });

            // Search functionality
            $(document).on('input', '#network-search-input', (e) => {
                this.handleSearch($(e.target).val());
            });

            // Clear all networks
            $(document).on('click', '#clear-all-networks', () => {
                this.clearAllNetworks();
            });

            // Remove individual network
            $(document).on('click', '.remove-network', (e) => {
                const networkCode = $(e.currentTarget).data('network');
                this.removeNetwork(networkCode);
            });
        }

        switchTab(clickedTab) {
            // Update active tab button
            $('.aebg-tab-button').removeClass('active');
            clickedTab.addClass('active');

            // Update active tab content
            const targetTab = clickedTab.data('tab');
            $('.aebg-tab-content').removeClass('active');
            $(`#tab-${targetTab}`).addClass('active');

            // Handle special cases
            if (targetTab === 'all') {
                this.populateAllNetworksTab();
            }
        }

        handleNetworkSelection(event) {
            const checkbox = $(event.target);
            const networkCode = checkbox.val();
            const isChecked = checkbox.prop('checked');
            const networkItem = checkbox.closest('.aebg-network-item');

            if (isChecked) {
                this.selectedNetworks.add(networkCode);
                networkItem.addClass('selected');
            } else {
                this.selectedNetworks.delete(networkCode);
                networkItem.removeClass('selected');
            }

            this.updateSelectedNetworksDisplay();
            this.updateHiddenInput();
        }

        handleSearch(searchTerm) {
            if (!searchTerm) {
                // Show all networks in current tab
                $('.aebg-network-item').show();
                return;
            }

            const searchLower = searchTerm.toLowerCase();
            
            $('.aebg-network-item').each((index, item) => {
                const $item = $(item);
                const networkName = $item.find('.network-name').text().toLowerCase();
                const networkCode = $item.find('input[type="checkbox"]').val().toLowerCase();
                
                if (networkName.includes(searchLower) || networkCode.includes(searchLower)) {
                    $item.show();
                } else {
                    $item.hide();
                }
            });
        }

        clearAllNetworks() {
            this.selectedNetworks.clear();
            $('.aebg-network-checkbox input[type="checkbox"]').prop('checked', false);
            $('.aebg-network-item').removeClass('selected');
            this.updateSelectedNetworksDisplay();
            this.updateHiddenInput();
        }

        removeNetwork(networkCode) {
            this.selectedNetworks.delete(networkCode);
            $(`.aebg-network-checkbox input[value="${networkCode}"]`).prop('checked', false);
            $(`.aebg-network-item:has(input[value="${networkCode}"])`).removeClass('selected');
            this.updateSelectedNetworksDisplay();
            this.updateHiddenInput();
        }

        updateSelectedNetworksDisplay() {
            const $selectedContainer = $('#selected-networks-container');
            $selectedContainer.empty();

            if (this.selectedNetworks.size === 0) {
                $selectedContainer.html('<p class="no-networks">No networks selected</p>');
                return;
            }

            this.selectedNetworks.forEach(networkCode => {
                const network = this.allNetworks.find(n => n.code === networkCode);
                if (network) {
                    const networkTag = $(`
                        <span class="selected-network-tag" data-network="${networkCode}">
                            ${network.name}
                            <button type="button" class="remove-network" data-network="${networkCode}">×</button>
                        </span>
                    `);
                    $selectedContainer.append(networkTag);
                }
            });
        }

        updateHiddenInput() {
            const $hiddenInput = $('#selected-networks-input');
            if ($hiddenInput.length) {
                $hiddenInput.val(Array.from(this.selectedNetworks).join(','));
                $hiddenInput.trigger('change');
            }

            // Trigger custom event for other scripts
            $(document).trigger('networksSelectionChanged', [Array.from(this.selectedNetworks)]);
        }

        updateCheckboxStates() {
            $('.aebg-network-checkbox input[type="checkbox"]').each((index, checkbox) => {
                const $checkbox = $(checkbox);
                const networkCode = $checkbox.val();
                const $networkItem = $checkbox.closest('.aebg-network-item');
                
                if (this.selectedNetworks.has(networkCode)) {
                    $checkbox.prop('checked', true);
                    $networkItem.addClass('selected');
                } else {
                    $checkbox.prop('checked', false);
                    $networkItem.removeClass('selected');
                }
            });
        }

        populateAllNetworksTab() {
            const $allNetworksContainer = $('#tab-all .aebg-networks-grid');
            if (!$allNetworksContainer.length) return;

            $allNetworksContainer.empty();

            this.allNetworks.forEach(network => {
                const isSelected = this.selectedNetworks.has(network.code);
                const networkItem = $(`
                    <div class="aebg-network-item ${isSelected ? 'selected' : ''}" data-network="${network.code}">
                        <div class="aebg-network-checkbox">
                            <input type="checkbox" id="network-${network.code}" value="${network.code}" ${isSelected ? 'checked' : ''}>
                            <label for="network-${network.code}">
                                <span class="network-name">${network.name}</span>
                                <span class="network-country">${network.country}</span>
                            </label>
                        </div>
                    </div>
                `);
                $allNetworksContainer.append(networkItem);
            });
        }

        getAllNetworks() {
            return [
                // US Networks
                { code: 'avantlink_us', name: 'AvantLink US', country: '🇺🇸 United States' },
                { code: 'avantlink_uk', name: 'AvantLink UK', country: '🇬🇧 United Kingdom' },
                { code: 'linkshare_us', name: 'LinkShare US', country: '🇺🇸 United States' },
                { code: 'linkshare_uk', name: 'LinkShare UK', country: '🇬🇧 United Kingdom' },
                { code: 'cj_us', name: 'Commission Junction US', country: '🇺🇸 United States' },
                { code: 'cj_uk', name: 'Commission Junction UK', country: '🇬🇧 United Kingdom' },
                { code: 'shareasale', name: 'ShareASale', country: '🇺🇸 United States' },
                { code: 'flexoffers', name: 'FlexOffers', country: '🇺🇸 United States' },
                { code: 'pepperjam', name: 'Pepperjam', country: '🇺🇸 United States' },
                { code: 'rakuten', name: 'Rakuten', country: '🇺🇸 United States' },
                { code: 'effinity', name: 'Effinity', country: '🇺🇸 United States' },
                { code: 'partnerize', name: 'Partnerize', country: '🇺🇸 United States' },
                { code: 'awin', name: 'AWIN', country: '🇪🇺 Europe' },
                { code: 'linkconnector', name: 'LinkConnector', country: '🇺🇸 United States' },
                
                // European Networks
                { code: 'tradetracker', name: 'TradeTracker', country: '🇪🇺 Europe' },
                { code: 'affiliate_window', name: 'Affiliate Window', country: '🇪🇺 Europe' },
                { code: 'webgains', name: 'Webgains', country: '🇪🇺 Europe' },
                { code: 'affilinet', name: 'Affilinet', country: '🇪🇺 Europe' },
                { code: 'daisycon', name: 'Daisycon', country: '🇪🇺 Europe' },
                { code: 'affiliate4you', name: 'Affiliate4You', country: '🇪🇺 Europe' },
                { code: 'affiliate_network', name: 'Affiliate Network', country: '🇪🇺 Europe' },
                
                // Scandinavian Networks
                { code: 'dk_elgiganten', name: 'Elgiganten', country: '🇩🇰 Denmark' },
                { code: 'dk_power', name: 'Power', country: '🇩🇰 Denmark' },
                { code: 'se_amazon', name: 'Amazon Sweden', country: '🇸🇪 Sweden' },
                { code: 'no_amazon', name: 'Amazon Norway', country: '🇳🇴 Norway' },
                
                // Amazon Networks
                { code: 'amazon_de', name: 'Amazon Germany', country: '🇩🇪 Germany' },
                { code: 'amazon_fr', name: 'Amazon France', country: '🇫🇷 France' },
                { code: 'amazon_it', name: 'Amazon Italy', country: '🇮🇹 Italy' },
                { code: 'amazon_es', name: 'Amazon Spain', country: '🇪🇸 Spain' },
                { code: 'amazon_ca', name: 'Amazon Canada', country: '🇨🇦 Canada' },
                
                // Performance Horizon Networks
                { code: 'performance_horizon_it', name: 'Performance Horizon Italy', country: '🇮🇹 Italy' },
                { code: 'performance_horizon_es', name: 'Performance Horizon Spain', country: '🇪🇸 Spain' },
                { code: 'performance_horizon_nl', name: 'Performance Horizon Netherlands', country: '🇳🇱 Netherlands' },
                
                // Other Major Networks
                { code: 'impact', name: 'Impact', country: '🇺🇸 United States' },
                { code: 'adservice', name: 'Adservice', country: '🇩🇰 Denmark' },
                { code: 'affiliate_gateway', name: 'Affiliate Gateway', country: '🇩🇰 Denmark' }
            ];
        }

        // Public methods for external use
        getSelectedNetworks() {
            return Array.from(this.selectedNetworks);
        }

        setSelectedNetworks(networks) {
            this.selectedNetworks.clear();
            if (Array.isArray(networks)) {
                networks.forEach(network => this.selectedNetworks.add(network));
            }
            this.updateCheckboxStates();
            this.updateSelectedNetworksDisplay();
            this.updateHiddenInput();
        }

        // Method to sync with old multi-select (for backward compatibility)
        syncWithOldSelector(oldSelectorId) {
            const oldSelector = $(`#${oldSelectorId}`);
            if (oldSelector.length) {
                const selectedValues = oldSelector.val() || [];
                this.setSelectedNetworks(selectedValues);
                
                // Update old selector when this one changes
                this.onSelectionChange = (networks) => {
                    oldSelector.val(networks);
                    oldSelector.trigger('change');
                };
            }
        }
    }

    // Initialize when document is ready
    if ($('.aebg-modern-network-selector').length) {
        window.modernNetworkSelector = new ModernNetworkSelector();
        
        // Sync with old selectors if they exist
        const oldSelectors = ['main-search-networks', 'modal-search-networks'];
        oldSelectors.forEach(selectorId => {
            if ($(`#${selectorId}`).length) {
                window.modernNetworkSelector.syncWithOldSelector(selectorId);
            }
        });
    }

    // Export for use in other scripts
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = ModernNetworkSelector;
    }
}); 