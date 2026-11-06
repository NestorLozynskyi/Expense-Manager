import Chart from 'chart.js/auto';
import { fmt, fmtCompact } from './ui.js';

/*
 * Chart.js with the design defaults (CONTRACT.md "Charts"): legend off (rendered in HTML),
 * money tooltips, compact y axis, doughnut cutout 68%, bars radius 4 / max 28px.
 *   <canvas data-chart='{"type":"doughnut","labels":[…],"datasets":[{"data":[…],"backgroundColor":[…]}]}' role="img" aria-label="…">
 * Values are in major currency units (display only).
 */
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function cssVar(name, fallback) {
    const v = getComputedStyle(document.documentElement).getPropertyValue(name);
    return (v && v.trim()) || fallback;
}
function colors() {
    return {
        income: cssVar('--chart-income', '#067647'),
        expense: cssVar('--chart-expense', '#C01048'),
        primary: cssVar('--chart-primary', '#1F4FD8'),
        grid: cssVar('--chart-grid', '#EAECF0'),
        axis: cssVar('--chart-axis', '#475467'),
        font: cssVar('--font-sans', 'system-ui, sans-serif'),
    };
}

let defaultsSet = false;
function options(type) {
    const c = colors();
    const base = {
        responsive: true,
        maintainAspectRatio: false,
        animation: reduceMotion ? false : { duration: 250, easing: 'easeOutQuart' },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#101828', titleColor: '#fff', bodyColor: '#fff', padding: 10, cornerRadius: 8, boxPadding: 4, usePointStyle: true,
                callbacks: {
                    label(ctx) {
                        const v = ctx.parsed && typeof ctx.parsed === 'object' ? ctx.parsed.y : ctx.parsed;
                        const name = type === 'doughnut' ? ctx.label : ctx.dataset.label;
                        return ` ${name ? name + ': ' : ''}${fmt(v)}`;
                    },
                },
            },
        },
    };
    if (type === 'doughnut') return { ...base, cutout: '68%', layout: { padding: 4 } };
    return {
        ...base,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: { grid: { display: false }, border: { color: c.grid }, ticks: { color: c.axis, font: { size: 12 } } },
            y: { beginAtZero: true, grid: { color: c.grid }, border: { display: false }, ticks: { color: c.axis, font: { size: 12 }, maxTicksLimit: 5, callback: (v) => fmtCompact(v) } },
        },
    };
}
function dataset(type, ds, i) {
    const c = colors();
    const auto = [c.income, c.expense, c.primary][i] || c.primary;
    if (type === 'doughnut') return { borderColor: '#fff', borderWidth: 2, hoverOffset: 4, ...ds };
    if (type === 'bar') return { backgroundColor: auto, borderRadius: 4, maxBarThickness: 28, categoryPercentage: 0.6, barPercentage: 0.85, ...ds };
    return ds;
}

export function initCharts(root) {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    const canvases = [];
    if (scope instanceof Element && scope.matches('canvas[data-chart]')) canvases.push(scope);
    canvases.push(...scope.querySelectorAll('canvas[data-chart]'));
    for (const canvas of canvases) {
        if (canvas._emChart) continue;
        if (!defaultsSet) {
            defaultsSet = true;
            const c = colors();
            Chart.defaults.font.family = c.font;
            Chart.defaults.font.size = 12;
            Chart.defaults.color = c.axis;
        }
        const cfg = JSON.parse(canvas.dataset.chart);
        Chart.getChart(canvas)?.destroy();
        canvas._emChart = new Chart(canvas, {
            type: cfg.type,
            data: { labels: cfg.labels, datasets: cfg.datasets.map((d, i) => dataset(cfg.type, d, i)) },
            options: options(cfg.type),
        });
    }
}
