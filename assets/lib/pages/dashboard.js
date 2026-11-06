/* Alpine components / behaviour for the dashboard screen. */
import { clearFieldError } from '../ui.js';

export function register(Alpine) {
    /*
     * Quick add: switching the type swaps the category list (categories of that type only)
     * and the amount tint / submit label (design: "Add expense" / "Add income").
     */
    Alpine.data('quickAdd', ({ type, categories }) => ({
        type,
        categories,
        fill() {
            const sel = this.$refs.category;
            const prev = sel.value;
            sel.innerHTML = '';
            sel.add(new Option('Choose category', ''));
            for (const c of this.categories[this.type] || []) sel.add(new Option(c.name, String(c.id)));
            if ([...sel.options].some((o) => o.value === prev)) sel.value = prev;
            clearFieldError(sel);
        },
    }));
}
