/**
 * Results Page JavaScript
 * Handles chart rendering and result interactions
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
        
        // Create chart
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'T-баллы',
                    data: scores,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    borderWidth: 3,
                    pointBackgroundColor: '#3498db',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 2,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.parsed.y;
                                let level = 'Норма';
                                if (value >= 55 && value < 65) level = 'Повышенный';
                                else if (value >= 65 && value < 75) level = 'Высокий';
                                else if (value >= 75) level = 'Очень высокий';
                                else if (value < 45) level = 'Низкий';
                                
                                return `T-балл: ${value} (${level})`;
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
                            stepSize: 10
                        },
                        grid: {
                            color: function(context) {
                                const value = context.tick.value;
                                if (value === 45 || value === 55 || value === 65 || value === 75) {
                                    return 'rgba(231, 76, 60, 0.3)';
                                }
                                return 'rgba(0, 0, 0, 0.1)';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
    
})();
