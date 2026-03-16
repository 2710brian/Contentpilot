/**
 * AEBG Frontend Comparison Table JavaScript
 * Modern, Interactive Features with Enhanced UX
 * Version: 1.0.0
 */

(function() {
    'use strict';

    class AEBGComparisonTable {
        constructor() {
            this.init();
        }

        init() {
            this.bindEvents();
            this.initializeAnimations();
            this.setupIntersectionObserver();
            this.enhanceAccessibility();
        }

        bindEvents() {
            // Bind click events for action buttons
            document.addEventListener('click', (e) => {
                if (e.target && e.target.nodeType === Node.ELEMENT_NODE && e.target.classList.contains('aebg-see-offer-btn')) {
                    this.handleOfferClick(e);
                }
            });

            // Bind hover events for enhanced interactions
            document.addEventListener('mouseenter', (e) => {
                if (e.target && e.target.nodeType === Node.ELEMENT_NODE && e.target.closest && e.target.closest('.aebg-comparison-table-frontend tbody tr')) {
                    this.handleRowHover(e);
                }
            }, true);

            // Bind focus events for accessibility
            document.addEventListener('focusin', (e) => {
                if (e.target && e.target.nodeType === Node.ELEMENT_NODE && e.target.closest && e.target.closest('.aebg-comparison-table-frontend tbody tr')) {
                    this.handleRowFocus(e);
                }
            });

            // Bind keyboard events
            document.addEventListener('keydown', (e) => {
                if (e.target && e.target.nodeType === Node.ELEMENT_NODE && e.target.closest && e.target.closest('.aebg-comparison-table-frontend')) {
                    this.handleKeyboardNavigation(e);
                }
            });

            // Bind touch events for mobile
            if ('ontouchstart' in window) {
                this.bindTouchEvents();
            }

            // Bind resize events for responsive behavior
            window.addEventListener('resize', this.debounce(() => {
                this.handleResize();
            }, 250));
        }

        handleOfferClick(e) {
            e.preventDefault();
            
            const button = e.target;
            const originalText = button.textContent;
            const originalBackground = button.style.background;
            
            // Add loading state
            button.textContent = 'Åbner...';
            button.style.background = 'linear-gradient(135deg, #9ca3af 0%, #6b7280 100%)';
            button.style.cursor = 'wait';
            
            // Simulate loading delay (remove this in production)
            setTimeout(() => {
                // Restore original state
                button.textContent = originalText;
                button.style.background = originalBackground;
                button.style.cursor = 'pointer';
                
                // Add success animation
                this.addSuccessAnimation(button);
                
                // Open the actual link if it exists
                const url = button.getAttribute('href') || button.dataset.url;
                if (url) {
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
            }, 800);
        }

        addSuccessAnimation(button) {
            button.style.transform = 'scale(1.05)';
            button.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.3)';
            
            setTimeout(() => {
                button.style.transform = '';
                button.style.boxShadow = '';
            }, 300);
        }

        handleRowHover(e) {
            if (!e.target || e.target.nodeType !== Node.ELEMENT_NODE || !e.target.closest) return;
            const row = e.target.closest('tr');
            if (!row) return;

            // Add subtle glow effect
            row.style.boxShadow = '0 8px 30px rgba(99, 102, 241, 0.15)';
            
            // Animate price column
            const priceCell = row.querySelector('td:nth-child(3)');
            if (priceCell) {
                priceCell.style.transform = 'scale(1.02)';
                priceCell.style.transition = 'transform 0.3s ease';
            }
        }

        handleRowFocus(e) {
            if (!e.target || e.target.nodeType !== Node.ELEMENT_NODE || !e.target.closest) return;
            const row = e.target.closest('tr');
            if (!row) return;

            // Add focus indicator
            row.style.outline = '2px solid #6366f1';
            row.style.outlineOffset = '3px';
            row.style.borderRadius = '8px';
        }

        handleKeyboardNavigation(e) {
            if (!e.target || e.target.nodeType !== Node.ELEMENT_NODE || !e.target.closest) return;
            const table = e.target.closest('.aebg-comparison-table-frontend');
            if (!table) return;

            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const currentRow = e.target.closest('tr');
            const currentIndex = rows.indexOf(currentRow);

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (currentIndex < rows.length - 1) {
                        rows[currentIndex + 1].focus();
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (currentIndex > 0) {
                        rows[currentIndex - 1].focus();
                    }
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    const actionButton = currentRow.querySelector('.aebg-see-offer-btn');
                    if (actionButton) {
                        actionButton.click();
                    }
                    break;
            }
        }

        bindTouchEvents() {
            let touchStartY = 0;
            let touchEndY = 0;

            document.addEventListener('touchstart', (e) => {
                touchStartY = e.touches[0].clientY;
            }, { passive: true });

            document.addEventListener('touchend', (e) => {
                touchEndY = e.changedTouches[0].clientY;
                this.handleTouchGesture(touchStartY, touchEndY);
            }, { passive: true });
        }

        handleTouchGesture(startY, endY) {
            const diff = startY - endY;
            const threshold = 50;

            if (Math.abs(diff) > threshold) {
                if (diff > 0) {
                    // Swipe up - could be used for additional actions
                    this.handleSwipeUp();
                } else {
                    // Swipe down - could be used for additional actions
                    this.handleSwipeDown();
                }
            }
        }

        handleSwipeUp() {
            // Add subtle animation for swipe up
            const table = document.querySelector('.aebg-comparison-table-frontend');
            if (table) {
                table.style.transform = 'translateY(-5px)';
                setTimeout(() => {
                    table.style.transform = '';
                }, 300);
            }
        }

        handleSwipeDown() {
            // Add subtle animation for swipe down
            const table = document.querySelector('.aebg-comparison-table-frontend');
            if (table) {
                table.style.transform = 'translateY(5px)';
                setTimeout(() => {
                    table.style.transform = '';
                }, 300);
            }
        }

        initializeAnimations() {
            // Add staggered animation for table rows
            const rows = document.querySelectorAll('.aebg-comparison-table-frontend tbody tr');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    row.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Add floating animation for action buttons
            const buttons = document.querySelectorAll('.aebg-see-offer-btn');
            buttons.forEach((button, index) => {
                button.style.animation = `float 3s ease-in-out ${index * 0.2}s infinite`;
            });
        }

        setupIntersectionObserver() {
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('aebg-visible');
                        }
                    });
                }, {
                    threshold: 0.1,
                    rootMargin: '0px 0px -50px 0px'
                });

                const table = document.querySelector('.aebg-comparison-table-frontend');
                if (table) {
                    observer.observe(table);
                }
            }
        }

        enhanceAccessibility() {
            // Add ARIA labels and roles
            const table = document.querySelector('.aebg-comparison-table-frontend');
            if (table) {
                table.setAttribute('role', 'table');
                table.setAttribute('aria-label', 'Product Price Comparison Table');
            }

            // Add ARIA labels to table headers
            const headers = document.querySelectorAll('.aebg-comparison-table-frontend th');
            headers.forEach((header, index) => {
                const labels = ['Product', 'Merchant', 'Price', 'Stock Status', 'Action'];
                if (labels[index]) {
                    header.setAttribute('aria-label', labels[index]);
                }
            });

            // Make rows focusable
            const rows = document.querySelectorAll('.aebg-comparison-table-frontend tbody tr');
            rows.forEach((row, index) => {
                row.setAttribute('tabindex', '0');
                row.setAttribute('role', 'row');
                row.setAttribute('aria-label', `Product comparison row ${index + 1}`);
            });

            // Add screen reader text for action buttons
            const actionButtons = document.querySelectorAll('.aebg-see-offer-btn');
            actionButtons.forEach((button, index) => {
                const merchantName = button.closest('tr').querySelector('td:nth-child(2)')?.textContent || 'merchant';
                button.setAttribute('aria-label', `View offer from ${merchantName}`);
            });
        }

        handleResize() {
            const table = document.querySelector('.aebg-comparison-table-frontend');
            if (!table) return;

            // Adjust table behavior based on screen size
            if (window.innerWidth <= 600) {
                // Mobile view optimizations
                this.optimizeForMobile(table);
            } else {
                // Desktop view optimizations
                this.optimizeForDesktop(table);
            }
        }

        optimizeForMobile(table) {
            // Add mobile-specific classes and behaviors
            table.classList.add('aebg-mobile-view');
            
            // Optimize touch targets
            const buttons = table.querySelectorAll('.aebg-see-offer-btn');
            buttons.forEach(button => {
                button.style.minHeight = '44px'; // iOS minimum touch target
                button.style.padding = '12px 20px';
            });
        }

        optimizeForDesktop(table) {
            // Remove mobile-specific classes
            table.classList.remove('aebg-mobile-view');
            
            // Restore desktop touch targets
            const buttons = table.querySelectorAll('.aebg-see-offer-btn');
            buttons.forEach(button => {
                button.style.minHeight = '';
                button.style.padding = '';
            });
        }

        // Utility function for debouncing
        debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Public method to refresh the table
        refresh() {
            // Trigger a refresh animation
            const table = document.querySelector('.aebg-comparison-table-frontend');
            if (table) {
                table.style.opacity = '0.5';
                table.style.transform = 'scale(0.98)';
                
                setTimeout(() => {
                    table.style.opacity = '1';
                    table.style.transform = 'scale(1)';
                }, 300);
            }
        }

        // Public method to add new rows dynamically
        addRow(rowData) {
            const tbody = document.querySelector('.aebg-comparison-table-frontend tbody');
            if (!tbody) return;

            const newRow = this.createRowElement(rowData);
            tbody.appendChild(newRow);
            
            // Animate the new row
            newRow.style.opacity = '0';
            newRow.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                newRow.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                newRow.style.opacity = '1';
                newRow.style.transform = 'translateY(0)';
            }, 100);
        }

        createRowElement(rowData) {
            const row = document.createElement('tr');
            row.setAttribute('tabindex', '0');
            row.setAttribute('role', 'row');
            
            // Create row content based on rowData
            // This is a simplified example - adjust based on your actual data structure
            row.innerHTML = `
                <td class="aebg-product-image" data-label="Produkt">
                    ${rowData.image ? `<img src="${rowData.image}" alt="${rowData.name}" />` : '<div class="aebg-no-image">Ingen billede</div>'}
                </td>
                <td data-label="Forhandler">${rowData.merchant}</td>
                <td data-label="Pris">${rowData.price}</td>
                <td class="aebg-stock-status ${rowData.availability}" data-label="Lagerstatus">${rowData.availabilityText}</td>
                <td data-label="Handling">
                    <a href="${rowData.url}" class="aebg-see-offer-btn" target="_blank">SE TILBUD</a>
                </td>
            `;
            
            return row;
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            new AEBGComparisonTable();
        });
    } else {
        new AEBGComparisonTable();
    }

    // Add CSS animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
        }
        
        .aebg-comparison-table-frontend tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .aebg-comparison-table-frontend tbody tr:hover {
            transform: translateY(-2px);
        }
        
        .aebg-see-offer-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .aebg-see-offer-btn:hover {
            transform: translateY(-3px);
        }
        
        .aebg-visible {
            animation: fadeInUp 0.8s ease forwards;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Mobile optimizations */
        .aebg-mobile-view .aebg-see-offer-btn {
            min-height: 44px;
            padding: 12px 20px;
        }
        
        /* Enhanced focus states */
        .aebg-comparison-table-frontend tbody tr:focus {
            outline: 2px solid #6366f1;
            outline-offset: 3px;
            border-radius: 8px;
        }
        
        /* Loading states */
        .aebg-loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        /* Success states */
        .aebg-success {
            animation: successPulse 0.6s ease-out;
        }
        
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    `;
    document.head.appendChild(style);

    // Export for global access if needed
    window.AEBGComparisonTable = AEBGComparisonTable;

})(); 