/*
 * Budgets screen: live preview in the set/edit budget dialog (design screens/budgets.html
 * updatePreview): state badge, "% used · $ left of $limit" and the progress bar for the
 * entered limit against what is already spent this month. Amounts are minor-unit integers.
 */
import { fmt } from '../ui.js';

const META = {
    ok: { label: 'On track', icon: 'check-circle' },
    warning: { label: 'Near limit', icon: 'alert-triangle' },
    over: { label: 'Over budget', icon: 'alert-circle' },
};

/** "1,234.5" → 123450 (minor units) or null when not a positive amount. */
export function parseMinor(value, digits) {
    const v = String(value || '').replace(/[\s,$]/g, '');
    if (!v || !/^\d*\.?\d*$/.test(v) || v === '.') return null;
    const [int, frac = ''] = v.split('.');
    if (frac.replace(/0+$/, '').length > digits) return null;
    const minor = Number((int || '0') + frac.padEnd(digits, '0').slice(0, digits));
    return minor > 0 && minor < 100_000_000 * 10 ** digits ? minor : null;
}

export function status(limit, spent) {
    const pct = Math.round((spent * 1000) / limit) / 10;
    const state = spent > limit ? 'over' : spent * 100 >= limit * 80 ? 'warning' : 'ok';
    return {
        pct,
        state,
        remaining: Math.max(limit - spent, 0),
        over: Math.max(spent - limit, 0),
        fill: state === 'over' ? 100 : Math.min(pct, 100),
        overflow: state === 'over' && spent > 0 ? Math.round(((spent - limit) * 1000) / spent) / 10 : 0,
    };
}

export function register(Alpine) {
    Alpine.data('budgetForm', (cfg) => ({
        scope: cfg.scope,
        category: cfg.category,
        limit: cfg.limit,
        get preview() {
            const id = cfg.mode === 'edit' ? cfg.category : (this.scope === 'overall' ? '' : this.category);
            if (this.scope !== 'overall' && !id) return null;
            const limit = parseMinor(this.limit, cfg.digits);
            if (limit === null) return null;
            const spent = id ? (cfg.spent[id] || 0) : cfg.total;
            const s = status(limit, spent);
            const m = 10 ** cfg.digits;
            const money = (minor) => fmt(minor / m);
            const pctTxt = `${s.pct.toFixed(0)}%`;
            const name = cfg.mode === 'edit' ? cfg.name : (id ? cfg.names[`c${id}`] : 'Overall monthly');
            return {
                ...s,
                name,
                label: META[s.state].label,
                icon: META[s.state].icon,
                text: `${pctTxt} used · ${s.state === 'over' ? `over by ${money(s.over)}` : `${money(s.remaining)} left`} of ${money(limit)}`,
                valueText: `${pctTxt} used, ${s.state === 'over' ? `over by ${money(s.over)}` : `${money(s.remaining)} left`}`,
            };
        },
    }));
}
