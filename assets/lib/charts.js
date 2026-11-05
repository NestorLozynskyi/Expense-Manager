import Chart from 'chart.js/auto';

/*
 * Declarative Chart.js binding:
 *   <canvas data-chart="bar" data-chart-target="ping-chart" ...>   renders a chart
 *   <template data-chart-update="ping-chart" data-chart-labels=".." data-chart-values="..">
 *     updates an existing chart (e.g. inside an HTMX fragment).
 */
const charts = new Map();

export function initCharts(root) {
    const scope = root instanceof Element || root instanceof Document ? root : document;

    collect(scope, 'canvas[data-chart]').forEach((canvas) => {
        const id = canvas.dataset.chartId;
        if (charts.has(id) && charts.get(id).canvas === canvas) {
            return;
        }
        charts.get(id)?.destroy();

        const chart = new Chart(canvas, {
            type: canvas.dataset.chart,
            data: {
                labels: JSON.parse(canvas.dataset.chartLabels || '[]'),
                datasets: [{
                    label: canvas.dataset.chartLabel || '',
                    data: JSON.parse(canvas.dataset.chartValues || '[]'),
                    backgroundColor: 'rgba(79, 70, 229, 0.6)',
                    borderColor: 'rgb(79, 70, 229)',
                    borderWidth: 1,
                }],
            },
            options: {
                animation: false,
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
        charts.set(id, chart);
        canvas.dataset.chartReady = 'true';
        canvas.dataset.chartTotal = String(chart.data.datasets[0].data.reduce((a, b) => a + b, 0));
    });

    collect(scope, '[data-chart-update]').forEach((el) => {
        const chart = charts.get(el.dataset.chartUpdate);
        if (!chart) {
            return;
        }
        chart.data.labels = JSON.parse(el.dataset.chartLabels || '[]');
        chart.data.datasets[0].data = JSON.parse(el.dataset.chartValues || '[]');
        chart.update();
        chart.canvas.dataset.chartTotal = String(chart.data.datasets[0].data.reduce((a, b) => a + b, 0));
    });
}

function collect(scope, selector) {
    const found = [...scope.querySelectorAll(selector)];
    if (scope instanceof Element && scope.matches(selector)) {
        found.unshift(scope);
    }
    return found;
}
