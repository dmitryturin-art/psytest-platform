/**
 * SMIL Classic Profile Chart
 * Classic MMPI-style profile with SVG overlay on background image
 * Based on psytest.org reference implementation
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initClassicProfile();
    });

    /**
     * Initialize classic SMIL profile chart
     */
    function initClassicProfile() {
        const container = document.getElementById('smilClassicProfile');
        if (!container) return;

        const scoresData = container.getAttribute('data-scores');
        const labelsData = container.getAttribute('data-labels');

        if (!scoresData || !labelsData) return;

        const scores = JSON.parse(scoresData);
        const labels = JSON.parse(labelsData);

        if (scores.length === 0) return;

        renderClassicProfile(container, scores, labels);
    }

    /**
     * Render classic MMPI profile with SVG
     */
    function renderClassicProfile(container, scores, labels) {
        // SVG viewBox dimensions (from reference)
        const viewBox = {
            width: 560,
            height: 621
        };

        // Calculate X positions for scales (evenly distributed)
        // Reference positions from psytest.org:
        // L=102, F=138, K=168, 1=208, 2=238, 3=270, 4=304, 5=338, 6=373, 7=412, 8=444, 9=478, 0=513
        const xPositions = [102, 138, 168, 208, 238, 270, 304, 338, 373, 412, 444, 478, 513];

        // Create HTML structure
        const html = `
            <div class="classic-profile-container">
                <div class="classic-profile-holder">
                    <img src="/images/smil-profile-bg.png" alt="СМИЛ профиль" class="profile-background">
                    <svg class="profile-overlay" viewBox="0 0 ${viewBox.width} ${viewBox.height}">
                        ${renderLines(scores, xPositions)}
                        ${renderPoints(scores, xPositions)}
                    </svg>
                </div>
            </div>
        `;

        container.innerHTML = html;
    }

    /**
     * Convert T-score to Y coordinate
     * Reference: T-score 30 = bottom, T-score 100 = top
     */
    function tScoreToY(tScore) {
        // Based on reference image analysis:
        // T=30 → y≈550
        // T=50 → y≈350
        // T=70 → y≈150
        // Linear interpolation: y = 650 - (tScore * 7)

        const minT = 20;
        const maxT = 100;
        const minY = 580;  // Bottom of chart
        const maxY = 40;   // Top of chart

        // Clamp T-score
        const clampedT = Math.max(minT, Math.min(maxT, tScore));

        // Linear interpolation
        const y = minY - ((clampedT - minT) / (maxT - minT)) * (minY - maxY);

        return y.toFixed(1);
    }

    /**
     * Render connecting lines between points
     */
    function renderLines(scores, xPositions) {
        let lines = '';

        for (let i = 0; i < scores.length - 1; i++) {
            const x1 = xPositions[i];
            const y1 = tScoreToY(scores[i]);
            const x2 = xPositions[i + 1];
            const y2 = tScoreToY(scores[i + 1]);

            lines += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="darkblue" stroke-width="4"/>`;
        }

        return lines;
    }

    /**
     * Render points for each scale
     */
    function renderPoints(scores, xPositions) {
        let points = '';

        for (let i = 0; i < scores.length; i++) {
            const x = xPositions[i];
            const y = tScoreToY(scores[i]);
            const color = getPointColor(scores[i]);

            points += `<circle cx="${x}" cy="${y}" fill="${color}" r="5" stroke="white" stroke-width="1"/>`;
        }

        return points;
    }

    /**
     * Get point color based on T-score
     * Green = normal (30-70), Red = elevated/lowered
     */
    function getPointColor(tScore) {
        if (tScore >= 30 && tScore <= 70) {
            return 'darkgreen';  // Normal range
        } else {
            return 'crimson';    // Elevated or lowered
        }
    }

})();
