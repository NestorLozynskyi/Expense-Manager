/*
 * Alpine.js components for page state. Behaviour from the approved mockups.
 */
import { modal } from './ui.js';
import * as dashboard from './pages/dashboard.js';
import * as transactions from './pages/transactions.js';
import * as categories from './pages/categories.js';
import * as budgets from './pages/budgets.js';

/** "23 h 41 min" / "23 h" / "10 min" — the demo banner wording. */
export function formatLeft(seconds) {
    const mins = Math.max(1, Math.ceil(seconds / 60));
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    if (h === 0) return { long: `${m} min`, short: `${m} min` };
    return { long: m ? `${h} h ${m} min` : `${h} h`, short: `${h} h` };
}

export function registerComponents(Alpine) {
    /*
     * Demo session countdown: banner text, warning tone + one-time warning dialog 10 minutes
     * before the end, then the "demo ended" screen. The server is the source of truth
     * (expiry is enforced on every request and the account is purged by the scheduler).
     */
    Alpine.data('demoTimer', ({ expiresAt, serverNow, warnSeconds, endedUrl }) => ({
        left: Math.max(0, expiresAt - serverNow),
        long: '',
        short: '',
        warning: false,
        init() {
            const offset = serverNow * 1000 - Date.now();
            const tick = () => {
                this.left = Math.max(0, Math.round((expiresAt * 1000 - (Date.now() + offset)) / 1000));
                const f = formatLeft(this.left);
                this.long = f.long;
                this.short = f.short;
                this.warning = this.left <= warnSeconds;
                if (this.left <= 0) { window.location.assign(endedUrl); return; }
                const key = `em-demo-warned-${expiresAt}`;
                if (this.warning && !sessionStorage.getItem(key)) {
                    sessionStorage.setItem(key, '1');
                    modal.open('demo-expiring');
                }
            };
            tick();
            this._timer = setInterval(tick, 15000);
        },
        destroy() { clearInterval(this._timer); },
    }));

    [dashboard, transactions, categories, budgets].forEach((page) => page.register(Alpine));
}
