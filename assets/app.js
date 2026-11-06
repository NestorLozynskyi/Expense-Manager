/*
 * Main JS entrypoint (AssetMapper + importmap, no Node build step).
 *  - HTMX: server-rendered fragments (filters, month switch, dialogs, form submits).
 *  - Alpine.js: small page state (demo timer, transaction form type, delete dialog).
 *  - ui.js: the design's UI primitives (modals, menus, tabs, combobox, toasts, validation).
 *  - charts.js: Chart.js with the design's defaults.
 */
import './styles/app.css';

import htmx from 'htmx.org';
import Alpine from 'alpinejs';
import * as ui from './lib/ui.js';
import { initCharts } from './lib/charts.js';
import { registerComponents } from './lib/components.js';

window.htmx = htmx;
window.Alpine = Alpine;
window.EM = ui;

// 422 = validation errors: swap the re-rendered form like a 200.
htmx.config.responseHandling = [
    { code: '204', swap: false },
    { code: '[23]..', swap: true },
    { code: '422', swap: true },
    { code: '[45]..', swap: false, error: true },
];
htmx.config.defaultFocusScroll = false;
htmx.config.historyCacheSize = 0;

ui.initUi();
registerComponents(Alpine);
Alpine.start();
initCharts(document);

// Server → client events (HX-Trigger response header).
document.addEventListener('em:toast', (e) => ui.toast(e.detail));
document.addEventListener('em:close-modals', () => ui.modal.closeAll({ instant: true, focus: false }));

document.body.addEventListener('htmx:beforeRequest', (e) => {
    const form = e.detail.elt.closest?.('form');
    const btn = form?.querySelector('[type="submit"][data-loading-label]');
    if (btn && e.detail.elt === form) ui.setLoading(btn, true, btn.dataset.loadingLabel);
});
document.body.addEventListener('htmx:afterRequest', (e) => {
    const form = e.detail.elt.closest?.('form');
    const btn = form?.querySelector('[type="submit"][data-loading-label]');
    if (btn && document.contains(btn)) ui.setLoading(btn, false);
});
document.body.addEventListener('htmx:afterSettle', (e) => {
    ui.hydrate(e.detail.elt);
    initCharts(e.detail.elt);
});
document.body.addEventListener('htmx:responseError', (e) => {
    if (e.detail.xhr.status === 401 || e.detail.xhr.status === 403) { window.location.reload(); return; }
    ui.toast({ type: 'error', title: 'Something went wrong', message: 'The change wasn’t saved. Try again.' });
});
document.body.addEventListener('htmx:sendError', () => {
    ui.toast({ type: 'error', title: 'You’re offline', message: 'Check your connection and try again.' });
});
