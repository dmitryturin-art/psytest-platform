/**
 * Visual Scale Indicator Component
 * Renders: [29] [30–70] [71–120] [0] with color zones
 */

(function() {
    'use strict';

    /**
     * Create visual scale indicator
     * @param {number} currentValue - Current T-score value
     * @param {string} containerId - Container element ID
     */
    function createScaleIndicator(currentValue, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        // Determine zone
        let zone = 'low';
        if (currentValue >= 30 && currentValue <= 70) zone = 'normal';
        else if (currentValue >= 71 && currentValue <= 120) zone = 'high';
        else if (currentValue > 120) zone = 'very-high';

        container.innerHTML = `
            <div class="visual-scale">
                <div class="scale-zones">
                    <div class="scale-zone low ${zone === 'low' ? 'active' : ''}">
                        <span class="zone-label">29</span>
                        <span class="zone-range">низкие</span>
                    </div>
                    <div class="scale-zone normal ${zone === 'normal' ? 'active' : ''}">
                        <span class="zone-range">30–70</span>
                        <span class="zone-label">норма</span>
                    </div>
                    <div class="scale-zone high ${zone === 'high' ? 'active' : ''}">
                        <span class="zone-range">71–120</span>
                        <span class="zone-range">высокие</span>
                    </div>
                    <div class="scale-zone very-high ${zone === 'very-high' ? 'active' : ''}">
                        <span class="zone-label">0</span>
                    </div>
                </div>
                <div class="scale-marker" style="--marker-position: ${calculateMarkerPosition(currentValue)}%">
                    <span class="marker-value">${currentValue}T</span>
                </div>
                <div class="scale-legend">
                    <span>низкие</span>
                    <span>⇒</span>
                    <span>средние</span>
                    <span>⇒</span>
                    <span>высокие значения</span>
                </div>
            </div>
        `;
    }

    /**
     * Calculate marker position (0-100%)
     */
    function calculateMarkerPosition(value) {
        if (value <= 29) return 5;
        if (value >= 120) return 95;
        
        // Map 30-120 to 15-85%
        return 15 + ((value - 30) / 90) * 70;
    }

    // Export to window
    window.createScaleIndicator = createScaleIndicator;

})();
