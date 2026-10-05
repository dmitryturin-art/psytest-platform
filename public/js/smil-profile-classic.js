/**
 * SMIL Classic Profile Chart
 * Classic MMPI-style profile with dual curves:
 * - Curve 1: Validity scales (L, F, K)
 * - Curve 2: Clinical scales (1-9, 0)
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
        const gridData = container.getAttribute('data-grid');

        if (!scoresData || !labelsData || !gridData) return;

        const scores = JSON.parse(scoresData);
        const labels = JSON.parse(labelsData);
        const grid = JSON.parse(gridData);

        if (scores.length === 0) return;
        // Without the blank's grid the points cannot be placed on its lines.
        if (!grid || !Array.isArray(grid.lines) || grid.lines.length < 2) return;

        renderClassicProfile(container, scores, labels, grid);
    }

    /**
     * Render classic MMPI profile with dual curves
     */
    function renderClassicProfile(container, scores, labels, grid) {
        // SVG viewBox dimensions (from reference)
        const viewBox = {
            width: 560,
            height: 621
        };

        // Split scores into two curves
        const validityScores = scores.slice(0, 3);   // L, F, K
        const clinicalScores = scores.slice(3);      // 1-9, 0

        const validityLabels = labels.slice(0, 3);
        const clinicalLabels = labels.slice(3);

        // X positions from reference (psytest.org)
        const validityPositions = [102, 138, 168];
        const clinicalPositions = [208, 238, 270, 304, 338, 373, 412, 444, 478, 513];

        // Create HTML structure
        const html = `
            <div class="classic-profile-container">
                <div class="classic-profile-holder">
                    <img src="/images/smil-profile-bg.png" alt="СМИЛ профиль" class="profile-background">
                    <svg class="profile-overlay" viewBox="0 0 ${viewBox.width} ${viewBox.height}">
                        ${renderCurve(validityScores, validityLabels, validityPositions, grid)}
                        ${renderCurve(clinicalScores, clinicalLabels, clinicalPositions, grid)}
                    </svg>
                </div>
                <div class="profile-legend">
                    <div class="legend-curve">
                        <div class="legend-line"></div>
                        <span>Кривая 1: Шкалы достоверности (L, F, K)</span>
                    </div>
                    <div class="legend-curve">
                        <div class="legend-line"></div>
                        <span>Кривая 2: Клинические шкалы (1-9, 0)</span>
                    </div>
                </div>
                <div class="profile-legend">
                    <div class="legend-item">
                        <div class="legend-point normal"></div>
                        <span>Норма (30-70T)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-point deviation"></div>
                        <span>Отклонение (&lt;30T или &gt;70T)</span>
                    </div>
                </div>
            </div>
        `;

        container.innerHTML = html;

        // Add tooltip element
        if (!document.getElementById('smil-tooltip')) {
            const tooltip = document.createElement('div');
            tooltip.id = 'smil-tooltip';
            tooltip.style.cssText = 'position: absolute; display: none; background: white; border: 1px solid #333; padding: 6px 10px; font-size: 12px; pointer-events: none; z-index: 1000;';
            document.body.appendChild(tooltip);
        }
    }

    /**
     * Render a single curve (validity or clinical)
     */
    function renderCurve(scores, labels, xPositions, grid) {
        let svg = '';

        // Render connecting lines
        for (let i = 0; i < scores.length - 1; i++) {
            const x1 = xPositions[i];
            const y1 = tScoreToY(scores[i], grid);
            const x2 = xPositions[i + 1];
            const y2 = tScoreToY(scores[i + 1], grid);

            svg += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="darkblue" stroke-width="4"/>`;
        }

        // Render points with tooltip support
        for (let i = 0; i < scores.length; i++) {
            const x = xPositions[i];
            const y = tScoreToY(scores[i], grid);
            const color = getPointColor(scores[i]);
            const tooltipText = `${labels[i]}: T=${scores[i]} (${getLevel(scores[i])})`;

            svg += `<circle cx="${x}" cy="${y}" fill="${color}" r="5" stroke="white" stroke-width="1"
                onmouseover="showTooltip(event, '${tooltipText}')"
                onmouseout="hideTooltip()"/>`;
        }

        return svg;
    }

    /**
     * Convert T-score to Y coordinate on the Sobchik blank (viewBox 560x621).
     *
     * The grid comes from the server (SmilProfileGrid, shared with the PDF
     * image): {t_min, t_max, lines: [[T, y], ...]} where y is the centre of
     * the blank's line labelled T. A T-score is clamped to [t_min, t_max]
     * and interpolated linearly between the neighbouring lines, so T = 70
     * sits exactly on the blank's "70" line.
     */
    function tScoreToY(tScore, grid) {
        const lines = grid.lines;
        const t = Math.max(grid.t_min, Math.min(grid.t_max, Number(tScore)));

        let y = lines[lines.length - 1][1];
        for (let i = 0; i < lines.length - 1; i++) {
            const lower = lines[i];
            const upper = lines[i + 1];
            if (t >= lower[0] && t <= upper[0]) {
                const ratio = (t - lower[0]) / (upper[0] - lower[0]);
                y = lower[1] + (upper[1] - lower[1]) * ratio;
                break;
            }
        }

        return y.toFixed(1);
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

    /**
     * Get T-score level label
     */
    function getLevel(tScore) {
        if (tScore < 30) return 'Низкий';
        if (tScore <= 70) return 'Норма';
        if (tScore <= 80) return 'Повышенный';
        return 'Высокий';
    }

    /**
     * Show tooltip
     */
    window.showTooltip = function(evt, text) {
        const tt = document.getElementById('smil-tooltip');
        if (tt) {
            tt.textContent = text;
            tt.style.left = (evt.pageX + 10) + 'px';
            tt.style.top = (evt.pageY - 20) + 'px';
            tt.style.display = 'block';
        }
    };

    /**
     * Hide tooltip
     */
    window.hideTooltip = function() {
        const tt = document.getElementById('smil-tooltip');
        if (tt) {
            tt.style.display = 'none';
        }
    };

})();
