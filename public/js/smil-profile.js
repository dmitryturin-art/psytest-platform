/**
 * SMIL Profile Chart Renderer
 * Draws the profile line chart with color-coded zones
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        initProfileChart();
    });

    /**
     * Initialize SMIL profile chart using Chart.js
     */
    function initProfileChart() {
        const canvas = document.getElementById('smilProfileChart');
        if (!canvas) return;

        const scores = JSON.parse(canvas.getAttribute('data-scores') || '[]');
        const labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');

        if (scores.length === 0) return;

        const ctx = canvas.getContext('2d');

        // Create gradient fill
        const gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(102, 126, 234, 0.8)');
        gradient.addColorStop(1, 'rgba(118, 75, 162, 0.2)');

        // Create chart
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'T-баллы',
                    data: scores,
                    borderColor: '#667eea',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: scores.map(score => getScoreColor(score)),
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 7,
                    pointHoverRadius: 9,
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 2.5,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(context) {
                                return 'Шкала ' + context[0].label;
                            },
                            label: function(context) {
                                const value = context.parsed.y;
                                let level = getLevelName(value);
                                return `T-балл: ${value} (${level})`;
                            }
                        }
                    },
                    annotation: {
                        annotations: {
                            line45: {
                                type: 'line',
                                yMin: 45,
                                yMax: 45,
                                borderColor: 'rgba(39, 174, 96, 0.3)',
                                borderWidth: 1,
                                borderDash: [5, 5],
                            },
                            line55: {
                                type: 'line',
                                yMin: 55,
                                yMax: 55,
                                borderColor: 'rgba(243, 156, 18, 0.3)',
                                borderWidth: 1,
                                borderDash: [5, 5],
                            },
                            line65: {
                                type: 'line',
                                yMin: 65,
                                yMax: 65,
                                borderColor: 'rgba(230, 126, 34, 0.3)',
                                borderWidth: 1,
                                borderDash: [5, 5],
                            },
                            line75: {
                                type: 'line',
                                yMin: 75,
                                yMax: 75,
                                borderColor: 'rgba(231, 76, 60, 0.3)',
                                borderWidth: 1,
                                borderDash: [5, 5],
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 20,
                        max: 100,
                        ticks: {
                            stepSize: 10,
                            callback: function(value) {
                                return value + 'T';
                            }
                        },
                        grid: {
                            color: function(context) {
                                const value = context.tick.value;
                                if (value === 45 || value === 55 || value === 65 || value === 75) {
                                    return 'rgba(0, 0, 0, 0.2)';
                                }
                                return 'rgba(0, 0, 0, 0.1)';
                            }
                        },
                        title: {
                            display: true,
                            text: 'T-баллы',
                            font: {
                                size: 14,
                                weight: 'bold'
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Шкалы',
                            font: {
                                size: 14,
                                weight: 'bold'
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Get color based on T-score level
     */
    function getScoreColor(score) {
        if (score <= 44) return '#3498db';      // Low - blue
        if (score <= 54) return '#27ae60';      // Normal - green
        if (score <= 64) return '#f39c12';      // Elevated - orange
        if (score <= 74) return '#e67e22';      // High - dark orange
        return '#e74c3c';                        // Very high - red
    }

    /**
     * Get level name for T-score
     */
    function getLevelName(score) {
        if (score <= 44) return 'Низкий';
        if (score <= 54) return 'Норма';
        if (score <= 64) return 'Повышенный';
        if (score <= 74) return 'Высокий';
        return 'Очень высокий';
    }

})();
