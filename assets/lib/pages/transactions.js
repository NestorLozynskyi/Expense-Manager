/* Transactions screen: add/edit dialog state (type ↔ categories, note counter) and focus handling. */
import { clearFieldError } from '../ui.js';

export function register(Alpine) {
    Alpine.data('txForm', ({ type, category, cats }) => ({
        type,
        category: String(category || ''),
        cats,
        noteLen: 0,
        init() {
            this.noteLen = this.$refs.note.value.length;
        },
        /* Switching type keeps the category only if it belongs to the new type (design). */
        setType(t) {
            this.type = t;
            const list = this.cats[t] || [];
            if (!list.some(([id]) => String(id) === this.category)) this.category = '';
            const sel = this.$refs.category;
            sel.replaceChildren(new Option('Choose a category', ''), ...list.map(([id, name]) => new Option(name, String(id))));
            sel.value = this.category;
            clearFieldError(sel);
        },
    }));
}

// After a 422 re-render of the dialog, move focus to the error summary (design behaviour).
document.addEventListener('htmx:afterSettle', (e) => {
    const summary = e.detail.elt.querySelector?.('#tf-errors:not([hidden])') || (e.detail.elt.matches?.('#tx-form') && e.detail.elt.querySelector('#tf-errors:not([hidden])'));
    if (summary) summary.focus();
    // "Load 25 more": focus the first newly shown row's first action.
    if (e.detail.elt.id !== 'main') return;
    const first = [...document.querySelectorAll('#tx-card tr[data-first-new] .cell-actions a, #tx-card tr[data-first-new] .cell-actions [data-popover]')]
        .find((el) => el.getClientRects().length);
    first?.focus({ preventScroll: true });
});
