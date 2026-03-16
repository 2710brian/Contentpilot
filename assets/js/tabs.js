/**
 * Reusable Tab Navigation Component
 * 
 * Usage:
 * const tabs = new AEBGTabs({
 *     container: '.aebg-tabs-wrapper',
 *     defaultTab: 'general',
 *     onTabChange: (tabId) => { console.log('Tab changed:', tabId); }
 * });
 * 
 * @package AEBG
 */
(function() {
    'use strict';

    class AEBGTabs {
        constructor(options = {}) {
            this.container = typeof options.container === 'string' 
                ? document.querySelector(options.container) 
                : options.container;
            
            if (!this.container) {
                console.error('AEBGTabs: Container not found');
                return;
            }

            this.defaultTab = options.defaultTab || null;
            this.onTabChange = options.onTabChange || null;
            this.useHash = options.useHash !== false; // Default to true
            this.animationDuration = options.animationDuration || 300;

            this.tabButtons = this.container.querySelectorAll('.aebg-tab-btn');
            this.tabPanels = this.container.querySelectorAll('.aebg-tab-panel');

            this.init();
        }

        init() {
            try {
                // Bind events
                this.tabButtons.forEach(button => {
                    button.addEventListener('click', (e) => this.handleTabClick(e));
                    button.addEventListener('keydown', (e) => this.handleKeyDown(e));
                });

                // Initialize from URL hash or default
                const hash = this.useHash ? window.location.hash.replace('#', '') : '';
                const initialTab = hash || this.defaultTab || this.getFirstTabId();

                if (initialTab) {
                    this.switchTab(initialTab, false); // Don't update hash on init
                }

                // Listen for hash changes (browser back/forward)
                if (this.useHash) {
                    window.addEventListener('hashchange', () => {
                        const hash = window.location.hash.replace('#', '');
                        if (hash) {
                            this.switchTab(hash, false);
                        }
                    });
                }
            } catch (error) {
                console.error('AEBGTabs initialization error:', error);
                this.fallbackToNoTabs();
            }
        }

        handleTabClick(e) {
            e.preventDefault();
            const button = e.currentTarget;
            const tabId = button.getAttribute('data-tab');

            if (tabId) {
                this.switchTab(tabId, true);
            }
        }

        handleKeyDown(e) {
            // Arrow key navigation
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                e.preventDefault();
                const currentIndex = Array.from(this.tabButtons).findIndex(
                    btn => btn.classList.contains('active')
                );
                const direction = e.key === 'ArrowLeft' ? -1 : 1;
                const nextIndex = (currentIndex + direction + this.tabButtons.length) % this.tabButtons.length;
                this.tabButtons[nextIndex].click();
                this.tabButtons[nextIndex].focus();
            }

            // Enter/Space to activate
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                e.currentTarget.click();
            }
        }

        switchTab(tabId, updateHash = true) {
            try {
                // Validate tab exists
                const targetButton = this.container.querySelector(`[data-tab="${tabId}"]`);
                const targetPanel = this.container.querySelector(`#aebg-tab-${tabId}`);

                if (!targetButton || !targetPanel) {
                    console.warn(`AEBGTabs: Tab "${tabId}" not found. Button: ${!!targetButton}, Panel: ${!!targetPanel}`);
                    return false;
                }

                // Remove active classes
                this.tabButtons.forEach(btn => btn.classList.remove('active'));
                this.tabPanels.forEach(panel => panel.classList.remove('active'));

                // Add active classes
                targetButton.classList.add('active');
                targetPanel.classList.add('active');

                // Update URL hash
                if (updateHash && this.useHash) {
                    window.history.replaceState(null, null, `#${tabId}`);
                }

                // Scroll to top of tab content
                targetPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });

                // Trigger callback
                if (this.onTabChange) {
                    this.onTabChange(tabId, targetButton, targetPanel);
                }

                // Dispatch custom event
                const event = new CustomEvent('aebg:tab:changed', {
                    detail: { tabId, button: targetButton, panel: targetPanel }
                });
                this.container.dispatchEvent(event);

                return true;
            } catch (error) {
                console.error('AEBGTabs switchTab error:', error);
                return false;
            }
        }

        getFirstTabId() {
            const firstButton = this.tabButtons[0];
            return firstButton ? firstButton.getAttribute('data-tab') : null;
        }

        getActiveTabId() {
            const activeButton = Array.from(this.tabButtons).find(
                btn => btn.classList.contains('active')
            );
            return activeButton ? activeButton.getAttribute('data-tab') : null;
        }

        fallbackToNoTabs() {
            // If tabs fail, show all content
            const panels = this.container.querySelectorAll('.aebg-tab-panel');
            panels.forEach(panel => {
                panel.style.display = 'block';
            });
        }
    }

    // Export to global scope
    window.AEBGTabs = AEBGTabs;
})();

