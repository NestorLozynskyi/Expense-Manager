/*
 * Main JS entrypoint (AssetMapper + importmap, no Node build step).
 * Libraries: HTMX (server-driven partial updates), Alpine.js (small client-side
 * state), Chart.js (charts). Tailwind CSS is compiled by symfonycasts/tailwind-bundle.
 */
import './styles/app.css';

import htmx from 'htmx.org';
import Alpine from 'alpinejs';
import { initCharts } from './lib/charts.js';

window.htmx = htmx;
window.Alpine = Alpine;

Alpine.start();

initCharts(document);
// Re-initialise / update charts inside fragments swapped in by HTMX.
document.body.addEventListener('htmx:afterSettle', (event) => initCharts(event.detail.elt));
