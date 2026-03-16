/**
 * Settings Page Tab Handler
 * Extends AEBGTabs with settings-specific functionality
 * 
 * @package AEBG
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const tabsContainer = document.querySelector('#aebg-settings-tabs');
        
        if (!tabsContainer) {
            return; // Not on settings page
        }

        // Initialize tabs
        const settingsTabs = new AEBGTabs({
            container: tabsContainer,
            defaultTab: 'general',
            useHash: true,
            onTabChange: function(tabId, button, panel) {
                // Settings-specific tab change logic
                handleSettingsTabChange(tabId, panel);
            }
        });

        // Handle settings-specific tab changes
        function handleSettingsTabChange(tabId, panel) {
            // Initialize logs tab when first opened
            if (tabId === 'logs' && typeof LogsPage !== 'undefined') {
                // Ensure logs are loaded when tab is activated
                if (!panel.dataset.initialized) {
                    panel.dataset.initialized = 'true';
                    // LogsPage will auto-initialize, but we can trigger refresh if needed
                    if (typeof LogsPage.loadLogs === 'function') {
                        LogsPage.loadLogs();
                    }
                }
            }

            // Initialize networks tab when first opened
            if (tabId === 'networks') {
                if (!panel.dataset.initialized) {
                    panel.dataset.initialized = 'true';
                    // NetworksTabManager initializes automatically, but ensure it's created
                    setTimeout(() => {
                        if (typeof NetworksPage === 'undefined') {
                            // Trigger initialization if not already done
                            const event = new Event('DOMContentLoaded');
                            document.dispatchEvent(event);
                        } else if (window.NetworksPage && window.NetworksPage.filterManager) {
                            // Re-initialize filter chips if needed
                            window.NetworksPage.filterManager.updateFilterChips();
                        }
                    }, 100);
                }
            }

            // Initialize competitor tracking tab when first opened
            if (tabId === 'competitor-tracking' && typeof CompetitorTracking !== 'undefined') {
                if (!panel.dataset.initialized) {
                    panel.dataset.initialized = 'true';
                    if (typeof CompetitorTracking.initialize === 'function') {
                        CompetitorTracking.initialize();
                    }
                }
            }

            // Save active tab to localStorage for persistence
            try {
                localStorage.setItem('aebg_settings_active_tab', tabId);
            } catch (e) {
                // Ignore localStorage errors
            }
        }

        // Restore last active tab from localStorage
        try {
            const savedTab = localStorage.getItem('aebg_settings_active_tab');
            if (savedTab && !window.location.hash) {
                settingsTabs.switchTab(savedTab, false);
            }
        } catch (e) {
            // Ignore localStorage errors
        }

        // Expose tabs instance for external access if needed
        window.aebgSettingsTabs = settingsTabs;

        // Ensure form collection works from all tabs
        // This function collects all form fields from all tabs
        window.collectAllSettings = function() {
            const formData = {};
            
            // Get all form elements regardless of tab visibility
            const allInputs = document.querySelectorAll(
                '#aebg-settings-tabs input, ' +
                '#aebg-settings-tabs select, ' +
                '#aebg-settings-tabs textarea'
            );
            
            allInputs.forEach(input => {
                const name = input.name;
                if (!name || !name.startsWith('aebg_settings[')) return;
                
                // Extract key from name="aebg_settings[key]"
                const key = name.match(/aebg_settings\[(.+?)\]/)?.[1];
                if (!key) return;
                
                // Handle different input types
                if (input.type === 'checkbox') {
                    formData[key] = input.checked ? '1' : '0';
                } else if (input.type === 'radio') {
                    if (input.checked) {
                        formData[key] = input.value;
                    }
                } else {
                    formData[key] = input.value || '';
                }
            });
            
            return formData;
        };
    });
})();

