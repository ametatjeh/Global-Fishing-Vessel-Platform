import { Chart, DoughnutController, ArcElement, Tooltip } from 'chart.js';

Chart.register(DoughnutController, ArcElement, Tooltip);

export function initVesselTypeBreakdownChart() {
    const cardEl = document.getElementById('vessel-type-breakdown-card');
    const canvas = document.getElementById('vessel-type-doughnut-chart');

    if (!cardEl || !canvas) {
        return;
    }

    let breakdownData = [];
    try {
        const rawJson = cardEl.getAttribute('data-breakdown');
        breakdownData = JSON.parse(rawJson || '[]');
    } catch (e) {
        console.error('Failed to parse vessel type breakdown data:', e);
        return;
    }

    if (!Array.isArray(breakdownData) || breakdownData.length === 0) {
        return;
    }

    const labels = breakdownData.map(item => item.label);
    const counts = breakdownData.map(item => item.count);
    const colors = breakdownData.map(item => item.color);
    const percentages = breakdownData.map(item => item.percentage);
    const totalCount = counts.reduce((a, b) => a + b, 0);

    // Custom plugin to render Total vessels text in the center cutout
    const centerTextPlugin = {
        id: 'centerTextPlugin',
        afterDraw(chart) {
            const { ctx, chartArea } = chart;
            if (!chartArea) return;

            const centerX = (chartArea.left + chartArea.right) / 2;
            const centerY = (chartArea.top + chartArea.bottom) / 2;

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            // Top: Total number formatted with commas
            const formattedTotal = Number(totalCount).toLocaleString('en-US');
            const fontSizeNumber = Math.max(16, Math.min(26, Math.round(chartArea.height * 0.14)));
            ctx.font = `800 ${fontSizeNumber}px "JetBrains Mono", Inter, ui-sans-serif, system-ui, sans-serif`;
            ctx.fillStyle = '#ffffff'; // white for dark theme
            ctx.fillText(formattedTotal, centerX, centerY - fontSizeNumber * 0.35);

            // Bottom: "Total Vessels" subtitle
            const fontSizeLabel = Math.max(9, Math.min(12, Math.round(fontSizeNumber * 0.45)));
            ctx.font = `600 ${fontSizeLabel}px Inter, ui-sans-serif, system-ui, sans-serif`;
            ctx.fillStyle = '#94a3b8'; // slate-400
            ctx.fillText('Total Vessels', centerX, centerY + fontSizeNumber * 0.65);

            ctx.restore();
        }
    };

    const chartInstance = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: colors,
                borderColor: '#0a1324',
                borderWidth: 2,
                hoverBorderColor: '#2dd4bf',
                hoverBorderWidth: 3,
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: {
                legend: {
                    display: false // We use our custom modern responsive legend list
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    titleColor: '#ffffff',
                    titleFont: { family: 'Inter, sans-serif', size: 13, weight: '700' },
                    bodyColor: '#cbd5e1',
                    bodyFont: { family: 'Inter, sans-serif', size: 12, weight: '500' },
                    padding: 10,
                    cornerRadius: 8,
                    displayColors: true,
                    boxWidth: 8,
                    boxHeight: 8,
                    usePointStyle: true,
                    callbacks: {
                        label(context) {
                            const val = context.parsed || 0;
                            const idx = context.dataIndex;
                            const pct = percentages[idx] !== undefined ? percentages[idx] : ((val / totalCount) * 100).toFixed(1);
                            return ` ${Number(val).toLocaleString('en-US')} vessels (${pct}%)`;
                        }
                    }
                }
            },
            animation: {
                animateRotate: true,
                animateScale: false,
                duration: 900,
                easing: 'easeOutQuart'
            },
            onHover(event, elements) {
                // Highlight corresponding category row in the breakdown list
                document.querySelectorAll('.vessel-type-item').forEach(el => {
                    el.classList.remove('bg-slate-800', 'ring-1', 'ring-teal-500/50');
                });
                if (elements && elements.length > 0) {
                    const idx = elements[0].index;
                    const itemEl = document.getElementById(`vessel-type-item-${idx}`);
                    if (itemEl) {
                        itemEl.classList.add('bg-slate-800', 'ring-1', 'ring-teal-500/50');
                    }
                }
            }
        },
        plugins: [centerTextPlugin]
    });

    // Two-way interaction: hovering list item highlights slice in Doughnut
    document.querySelectorAll('.vessel-type-item').forEach(itemEl => {
        const idx = parseInt(itemEl.getAttribute('data-index'), 10);
        if (isNaN(idx)) return;

        itemEl.addEventListener('mouseenter', () => {
            chartInstance.setActiveElements([{ datasetIndex: 0, index: idx }]);
            chartInstance.tooltip?.setActiveElements([{ datasetIndex: 0, index: idx }], { x: 0, y: 0 });
            chartInstance.update();
        });

        itemEl.addEventListener('mouseleave', () => {
            chartInstance.setActiveElements([]);
            chartInstance.tooltip?.setActiveElements([], { x: 0, y: 0 });
            chartInstance.update();
        });
    });

    window._vesselTypeBreakdownChart = chartInstance;
}

// Auto-run on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initVesselTypeBreakdownChart);
} else {
    initVesselTypeBreakdownChart();
}

