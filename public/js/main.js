/**
 * PsyTest Platform - Main JavaScript
 */

(function() {
    'use strict';
    
    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        initTooltips();
        initFormValidation();
        initSmoothScroll();
    });
    
    /**
     * Initialize tooltips
     */
    function initTooltips() {
        const tooltipTriggers = document.querySelectorAll('[data-tooltip]');
        
        tooltipTriggers.forEach(trigger => {
            trigger.addEventListener('mouseenter', showTooltip);
            trigger.addEventListener('mouseleave', hideTooltip);
        });
    }
    
    function showTooltip(e) {
        const tooltip = e.target.getAttribute('data-tooltip');
        if (!tooltip) return;
        
        const tooltipEl = document.createElement('div');
        tooltipEl.className = 'tooltip';
        tooltipEl.textContent = tooltip;
        document.body.appendChild(tooltipEl);
        
        const rect = e.target.getBoundingClientRect();
        tooltipEl.style.top = rect.top - tooltipEl.offsetHeight - 10 + 'px';
        tooltipEl.style.left = rect.left + (rect.width - tooltipEl.offsetWidth) / 2 + 'px';
        
        e.target._tooltip = tooltipEl;
    }
    
    function hideTooltip(e) {
        if (e.target._tooltip) {
            e.target._tooltip.remove();
            e.target._tooltip = null;
        }
    }
    
    /**
     * Initialize form validation
     */
    function initFormValidation() {
        const forms = document.querySelectorAll('form[data-validate]');
        
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!validateForm(form)) {
                    e.preventDefault();
                }
            });
        });
    }
    
    function validateForm(form) {
        let isValid = true;
        const inputs = form.querySelectorAll('[required]');
        
        inputs.forEach(input => {
            if (!input.value.trim()) {
                isValid = false;
                input.classList.add('error');
            } else {
                input.classList.remove('error');
            }
        });
        
        return isValid;
    }
    
    /**
     * Initialize smooth scroll for anchor links
     */
    function initSmoothScroll() {
        const anchorLinks = document.querySelectorAll('a[href^="#"]');
        
        anchorLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }
    
    /**
     * Utility: Format date
     */
    window.formatDate = function(dateString, locale = 'ru-RU') {
        const date = new Date(dateString);
        return date.toLocaleDateString(locale, {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };
    
    /**
     * Utility: Copy to clipboard
     */
    window.copyToClipboard = async function(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (err) {
            console.error('Failed to copy:', err);
            return false;
        }
    };
    
    /**
     * Utility: Show loading state on button
     */
    window.setButtonLoading = function(button, loading = true) {
        if (loading) {
            button.disabled = true;
            button._originalText = button.textContent;
            button.textContent = button.getAttribute('data-loading-text') || 'Загрузка...';
        } else {
            button.disabled = false;
            button.textContent = button._originalText || 'Готово';
        }
    };
    
})();
