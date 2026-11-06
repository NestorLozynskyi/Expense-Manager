/*
 * UI primitives of the approved design (assets/CONTRACT.md §5) for server-rendered pages.
 * The markup/data-attributes are the design's; behaviour is implemented here once, with
 * delegated listeners, so it also works for fragments swapped in by HTMX:
 *   modals [data-open-modal]/[data-close-modal] · menus & popovers [data-popover] ·
 *   tabs [role=tab] · password toggle · combobox [data-combobox] · toasts · form validation
 *   ([data-validate]) · loading buttons.
 * Page-level state lives in Alpine components (assets/lib/components.js).
 */

const doc = document;
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

export const qsa = (sel, root = doc) => Array.from(root.querySelectorAll(sel));
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const icon = (name, size = 20, cls = '') =>
    `<svg class="icon icon--${size}${cls ? ' ' + cls : ''}" width="${size}" height="${size}" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#i-${name}"></use></svg>`;

function emit(target, name, detail) {
    target.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
}
function focusables(root) {
    return qsa(FOCUSABLE, root).filter((el) => el.getClientRects().length && !el.closest('[hidden]'));
}
function focus(el) {
    if (!el) return;
    try { el.focus({ preventScroll: true }); } catch { el.focus(); }
}

/* ---------------- Money / dates (Intl en-US, narrowSymbol, U+2212) ---------------- */
const fmtCache = {};
export function currency() {
    return doc.body?.dataset.currency || 'USD';
}
export function fmt(amount, cur = currency()) {
    let f = fmtCache[cur];
    if (!f) {
        try { f = new Intl.NumberFormat('en-US', { style: 'currency', currency: cur, currencyDisplay: 'narrowSymbol' }); }
        catch { f = new Intl.NumberFormat('en-US', { style: 'currency', currency: cur }); }
        fmtCache[cur] = f;
    }
    return f.format(Number(amount) || 0).replace('-', '−');
}
export function fmtSigned(amount, type, cur) {
    const n = Number(amount) || 0;
    return (type === 'income' ? '+' : '−') + fmt(Math.abs(n), cur);
}
export function fmtCompact(amount, cur = currency()) {
    try {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: cur, currencyDisplay: 'narrowSymbol', notation: 'compact', maximumFractionDigits: 1 }).format(amount);
    } catch { return fmt(amount, cur); }
}
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function parseISO(iso) { const p = String(iso).split('-'); return new Date(+p[0], +p[1] - 1, +(p[2] || 1)); }
export function fmtDate(iso, style = 'medium') {
    const d = parseISO(iso);
    const m = MONTHS[d.getMonth()];
    return style === 'short' ? `${m} ${d.getDate()}` : `${m} ${d.getDate()}, ${d.getFullYear()}`;
}
export function fmtRange(from, to) {
    const sameYear = parseISO(from).getFullYear() === parseISO(to).getFullYear();
    return `${fmtDate(from, sameYear ? 'short' : 'medium')} – ${fmtDate(to)}`;
}

/* ---------------- Toasts ---------------- */
const TOAST_ICON = { success: 'check-circle', error: 'alert-circle', info: 'info' };
function toastRegion() {
    let r = doc.getElementById('em-toasts');
    if (!r) {
        r = doc.createElement('div');
        r.id = 'em-toasts';
        r.className = 'toast-region';
        r.setAttribute('role', 'status');
        r.setAttribute('aria-live', 'polite');
        r.setAttribute('aria-relevant', 'additions');
        doc.body.appendChild(r);
    }
    return r;
}
function armToast(el, duration = 4000) {
    let timer = null;
    let closed = false;
    const close = () => {
        if (closed) return;
        closed = true;
        clearTimeout(timer);
        el.classList.add('is-leaving');
        setTimeout(() => el.remove(), reduceMotion ? 0 : 140);
    };
    const arm = () => { if (duration > 0) timer = setTimeout(close, duration); };
    el.querySelector('.toast__close')?.addEventListener('click', close);
    el.addEventListener('mouseenter', () => clearTimeout(timer));
    el.addEventListener('mouseleave', arm);
    el.addEventListener('focusin', () => clearTimeout(timer));
    arm();
    return { el, close };
}
/** toast({type:'success'|'error'|'info', title, message, duration}) */
export function toast({ type = 'info', title = '', message = '', duration = 4000 } = {}) {
    const el = doc.createElement('div');
    el.className = `toast toast--${type}`;
    el.innerHTML = icon(TOAST_ICON[type] || 'info', 20)
        + '<div class="toast__body">'
        + (title ? `<p class="toast__title">${esc(title)}</p>` : '')
        + (message ? `<p class="toast__message">${esc(message)}</p>` : '')
        + '</div>'
        + `<button type="button" class="btn btn--ghost btn--icon btn--sm toast__close" aria-label="Dismiss notification">${icon('x', 16)}</button>`;
    toastRegion().appendChild(el);
    return armToast(el, duration);
}

/* ---------------- Modal / bottom sheet ---------------- */
const modalStack = [];
export const modal = {
    stack: modalStack,
    open(id, opts = {}) {
        const m = typeof id === 'string' ? doc.getElementById(id) : id;
        if (!m || modalStack.includes(m)) return;
        popover.closeAll();
        m._emReturn = opts.returnFocus || doc.activeElement;
        m.classList.remove('is-closing');
        m.hidden = false;
        modalStack.push(m);
        doc.body.classList.add('modal-open');
        const dlg = m.querySelector('.modal__dialog') || m;
        if (!dlg.hasAttribute('tabindex')) dlg.setAttribute('tabindex', '-1');
        if (opts.focus !== false) {
            focus(m.querySelector('[data-autofocus]') || focusables(m.querySelector('.modal__body') || m)[0] || dlg);
        }
        emit(m, 'em:modal-open', { id: m.id });
    },
    close(id, opts = {}) {
        let m = typeof id === 'string' ? doc.getElementById(id) : id;
        if (!m) m = modalStack[modalStack.length - 1];
        if (!m || m.hidden) return;
        const i = modalStack.indexOf(m);
        if (i !== -1) modalStack.splice(i, 1);
        const finish = () => {
            m.hidden = true;
            m.classList.remove('is-closing');
            if (!modalStack.length) doc.body.classList.remove('modal-open');
            // Server-rendered dialogs loaded into #modal-root are disposable.
            if (m.hasAttribute('data-dispose')) m.remove();
        };
        if (reduceMotion || opts.instant) finish(); else { m.classList.add('is-closing'); setTimeout(finish, 130); }
        const r = m._emReturn;
        if (opts.focus !== false && r && doc.contains(r)) focus(r);
        emit(m, 'em:modal-close', { id: m.id });
    },
    closeAll(opts = {}) {
        modalStack.slice().reverse().forEach((m) => modal.close(m, opts));
    },
    isOpen(id) { const m = doc.getElementById(id); return !!m && !m.hidden; },
};

/* ---------------- Popovers & menus ---------------- */
const openPops = [];
function popTrigger(pop) {
    const out = qsa(`[data-popover="${pop.id}"]`).find((x) => !pop.contains(x));
    if (out) return out;
    const dd = pop.closest('.dropdown');
    return dd ? qsa('[data-popover]', dd).find((x) => !pop.contains(x)) || null : null;
}
export const popover = {
    open(id, opts = {}) {
        const pop = typeof id === 'string' ? doc.getElementById(id) : id;
        if (!pop || openPops.includes(pop)) return;
        openPops.slice().forEach((p) => { if (!p.contains(pop)) popover.close(p, { focus: false }); });
        pop.hidden = false;
        openPops.push(pop);
        const t = opts.trigger || popTrigger(pop);
        pop._emTrigger = t;
        t?.setAttribute('aria-expanded', 'true');
        if (opts.focus !== false) {
            const first = pop.getAttribute('role') === 'menu'
                ? (pop.querySelector('[role^="menuitem"][aria-checked="true"]') || pop.querySelector('[role^="menuitem"]'))
                : (pop.querySelector('[data-autofocus]') || focusables(pop)[0]);
            focus(first);
        }
        emit(pop, 'em:popover-open', { id: pop.id });
    },
    close(id, opts = {}) {
        const pop = typeof id === 'string' ? doc.getElementById(id) : id;
        if (!pop) return;
        const i = openPops.indexOf(pop);
        if (i !== -1) openPops.splice(i, 1);
        pop.hidden = true;
        const t = pop._emTrigger || popTrigger(pop);
        if (t) {
            t.setAttribute('aria-expanded', 'false');
            if (opts.focus) focus(t);
        }
        emit(pop, 'em:popover-close', { id: pop.id });
    },
    toggle(id, opts = {}) {
        const pop = doc.getElementById(id);
        if (!pop) return;
        if (pop.hidden) popover.open(pop, opts); else popover.close(pop, { focus: true });
    },
    closeAll() { openPops.slice().forEach((p) => popover.close(p, { focus: false })); },
};

/* ---------------- Tabs ---------------- */
function selectTab(tab, doFocus) {
    const list = tab.closest('[role="tablist"]');
    qsa('[role="tab"]', list).forEach((t) => {
        const on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.setAttribute('tabindex', on ? '0' : '-1');
        const p = doc.getElementById(t.getAttribute('aria-controls'));
        if (p) p.hidden = !on;
    });
    if (doFocus) focus(tab);
    emit(tab, 'em:tab', { tab: tab.id, panel: tab.getAttribute('aria-controls'), value: tab.dataset.value });
}

/* ---------------- Combobox (searchable listbox) ---------------- */
let uid = 0;
const ensureId = (el, prefix) => el.id || (el.id = `${prefix}-${++uid}`);
export function combobox(root) {
    if (!root || root._emCombo) return root?._emCombo || null;
    const trigger = root.querySelector('.combobox__trigger');
    const pop = root.querySelector('.combobox__popover');
    const search = root.querySelector('.combobox__search');
    const list = root.querySelector('.combobox__list');
    const empty = root.querySelector('.combobox__empty');
    const hidden = root.querySelector('input[type="hidden"]');
    const valueEl = root.querySelector('.combobox__value');
    const options = qsa('[role="option"]', list);
    let active = -1;
    ensureId(list, 'cb-list');
    options.forEach((o) => ensureId(o, 'cb-opt'));
    if (search) {
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-controls', list.id);
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-expanded', 'true');
    }
    trigger?.setAttribute('aria-haspopup', 'listbox');
    trigger?.setAttribute('aria-expanded', 'false');

    const visible = () => options.filter((o) => !o.hidden);
    function setActive(i) {
        const v = visible();
        options.forEach((o) => o.classList.remove('is-active'));
        if (!v.length) { active = -1; search?.removeAttribute('aria-activedescendant'); return; }
        active = Math.max(0, Math.min(i, v.length - 1));
        const o = v[active];
        o.classList.add('is-active');
        search?.setAttribute('aria-activedescendant', o.id);
        o.scrollIntoView?.({ block: 'nearest' });
    }
    function filter() {
        const q = search ? search.value.trim().toLowerCase() : '';
        let n = 0;
        options.forEach((o) => { const hit = !q || o.textContent.toLowerCase().includes(q); o.hidden = !hit; if (hit) n++; });
        if (empty) { empty.hidden = n > 0; if (!n) empty.textContent = `No currencies match “${search.value.trim()}”`; }
        setActive(0);
    }
    function open(o = {}) {
        if (!pop.hidden) return;
        pop.hidden = false;
        trigger?.setAttribute('aria-expanded', 'true');
        if (search) { search.value = o.query || ''; filter(); }
        const sel = options.find((x) => x.getAttribute('aria-selected') === 'true');
        setActive(sel ? visible().indexOf(sel) : 0);
        if (o.focus !== false && search) focus(search);
    }
    function close(o = {}) {
        if (pop.hidden) return;
        pop.hidden = true;
        trigger?.setAttribute('aria-expanded', 'false');
        if (o.focus && trigger) trigger.focus();
    }
    function select(value) {
        const o = options.find((x) => x.dataset.value === value);
        if (!o) return;
        options.forEach((x) => x.setAttribute('aria-selected', x === o ? 'true' : 'false'));
        if (hidden) hidden.value = value;
        if (valueEl) valueEl.textContent = o.dataset.label || o.textContent.trim();
        root.dataset.value = value;
        emit(root, 'em:combobox-change', { value, label: valueEl ? valueEl.textContent : value });
    }
    trigger?.addEventListener('click', () => (pop.hidden ? open() : close({ focus: true })));
    search?.addEventListener('input', filter);
    root.addEventListener('keydown', (e) => {
        if (pop.hidden) {
            if (e.target === trigger && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); open(); }
            return;
        }
        const v = visible();
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter') { e.preventDefault(); if (v[active]) { select(v[active].dataset.value); close({ focus: true }); } }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close({ focus: true }); }
        else if (e.key === 'Tab') { close(); }
    });
    list.addEventListener('click', (e) => {
        const o = e.target.closest('[role="option"]');
        if (o) { select(o.dataset.value); close({ focus: true }); }
    });
    list.addEventListener('mousemove', (e) => {
        const o = e.target.closest('[role="option"]');
        if (o) setActive(visible().indexOf(o));
    });
    doc.addEventListener('click', (e) => { if (!root.contains(e.target)) close(); });
    const api = { el: root, open, close, select, get value() { return hidden ? hidden.value : root.dataset.value; } };
    root._emCombo = api;
    return api;
}

/* ---------------- Field errors, error summary, loading ---------------- */
export function setFieldError(input, message) {
    const field = input.closest('.field') || input.parentNode;
    let err = field.querySelector('.field__error');
    const errId = `${ensureId(input, 'f')}-error`;
    input.setAttribute('aria-invalid', 'true');
    input.closest('.input-group')?.classList.add('is-invalid');
    if (!err) { err = doc.createElement('p'); err.className = 'field__error'; field.appendChild(err); }
    err.id = errId;
    err.innerHTML = icon('alert-circle', 16) + `<span>${esc(message)}</span>`;
    const d = (input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
    if (!d.includes(errId)) d.push(errId);
    input.setAttribute('aria-describedby', d.join(' '));
}
export function clearFieldError(input) {
    const field = input.closest('.field') || input.parentNode;
    const err = field.querySelector('.field__error');
    input.removeAttribute('aria-invalid');
    input.closest('.input-group')?.classList.remove('is-invalid');
    if (err) {
        const d = (input.getAttribute('aria-describedby') || '').split(' ').filter((x) => x && x !== err.id);
        if (d.length) input.setAttribute('aria-describedby', d.join(' ')); else input.removeAttribute('aria-describedby');
        err.remove();
    }
}
export function renderSummary(summary, errors) {
    if (!summary) return;
    if (!errors.length) { summary.hidden = true; summary.innerHTML = ''; return; }
    summary.innerHTML = icon('alert-circle', 20) + '<div>'
        + `<p class="error-summary__title">${errors.length === 1 ? 'Fix 1 field to continue' : `Fix ${errors.length} fields to continue`}</p>`
        + '<ul class="error-summary__list">'
        + errors.map((e) => `<li><a href="#${e.id}" data-focus="${e.id}">${esc(e.msg)}</a></li>`).join('')
        + '</ul></div>';
    summary.hidden = false;
}
export function setLoading(btn, on, label) {
    if (!btn) return;
    if (on) {
        if (btn.classList.contains('is-loading')) return;
        btn._emHTML = btn.innerHTML;
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        btn.setAttribute('aria-disabled', 'true');
        btn.innerHTML = `<span class="spinner" aria-hidden="true"></span><span>${esc(label || btn.textContent.trim())}</span>`;
    } else {
        btn.classList.remove('is-loading');
        btn.removeAttribute('aria-busy');
        btn.removeAttribute('aria-disabled');
        if (btn._emHTML != null) btn.innerHTML = btn._emHTML;
    }
}

/*
 * Declarative client-side validation (mirrors the server rules; the server validates again):
 *   <form data-validate data-summary="#summary-id">
 *     <input data-rules="required email" data-msg-required="Enter your email address" …>
 * Rules: required, email, min:N, max:N, amount (> 0), date, notbefore:#otherInputId.
 * The first invalid submit validates all fields; afterwards fields re-validate on input/blur.
 */
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
function fieldValue(el) {
    if (el.matches('.combobox__trigger')) return el.closest('[data-combobox]')?.querySelector('input[type="hidden"]')?.value || '';
    if (el.type === 'radio') return doc.querySelector(`input[name="${el.name}"]:checked`)?.value || '';
    return el.value;
}
function checkField(el) {
    const v = fieldValue(el).trim();
    for (const rule of (el.dataset.rules || '').split(/\s+/).filter(Boolean)) {
        const [name, arg] = rule.split(':');
        const msg = el.dataset[`msg${name.charAt(0).toUpperCase()}${name.slice(1)}`];
        if (name === 'required' && !v) return msg || 'This field is required';
        if (!v) continue;
        if (name === 'email' && !EMAIL_RE.test(v)) return msg || 'Enter a valid email address, like name@example.com';
        if (name === 'min' && fieldValue(el).length < +arg) return msg || `Use at least ${arg} characters`;
        if (name === 'max' && v.length > +arg) return msg || `Use ${arg} characters or fewer`;
        if (name === 'amount') {
            const n = v.replace(/[\s,]/g, '');
            if (!/^\d*(\.\d*)?$/.test(n) || n === '.') return el.dataset.msgNumber || 'Enter a number, like 12.50';
            if (!(parseFloat(n) > 0)) return msg || 'Enter an amount greater than 0';
        }
        if (name === 'notbefore') {
            const other = doc.querySelector(arg);
            if (other?.value && v < other.value) return msg || 'End date must be on or after the start date';
        }
    }
    return '';
}
function validateForm(form) {
    const errors = [];
    qsa('[data-rules]', form).forEach((el) => {
        if (el.closest('[hidden]') || el.disabled) { clearFieldError(el); return; }
        const msg = checkField(el);
        if (msg) { setFieldError(el, msg); errors.push({ id: el.id, msg }); } else clearFieldError(el);
    });
    form._emValidated = true;
    const summary = form.dataset.summary ? doc.querySelector(form.dataset.summary) : null;
    renderSummary(summary, errors);
    return errors;
}
function revalidateField(el) {
    const form = el.closest('form[data-validate]');
    if (!form || !el.matches('[data-rules]')) return;
    if (!form._emValidated && el.getAttribute('aria-invalid') !== 'true') return;
    const msg = checkField(el);
    if (msg) setFieldError(el, msg); else clearFieldError(el);
    const summary = form.dataset.summary ? doc.querySelector(form.dataset.summary) : null;
    if (summary && !summary.hidden) {
        renderSummary(summary, qsa('[data-rules][aria-invalid="true"]', form).map((x) => ({ id: x.id, msg: checkField(x) })).filter((x) => x.msg));
    }
}

/* ---------------- Delegated events ---------------- */
function onClick(e) {
    const t = e.target;
    if (!t?.closest) return;
    let el;
    openPops.slice().forEach((p) => {
        const tr = p._emTrigger || popTrigger(p);
        if (!p.contains(t) && !(tr && tr.contains(t))) popover.close(p, { focus: false });
    });
    if ((el = t.closest('[data-focus]'))) {
        e.preventDefault();
        const target = doc.getElementById(el.dataset.focus);
        if (target) { focus(target); target.scrollIntoView?.({ block: 'center' }); }
        return;
    }
    if ((el = t.closest('[data-open-modal]'))) {
        e.preventDefault();
        const fromMenu = el.closest('.menu, .popover');
        const rf = fromMenu ? (fromMenu._emTrigger || popTrigger(fromMenu) || el) : el;
        modal.open(el.dataset.openModal, { returnFocus: rf });
        return;
    }
    if ((el = t.closest('[data-close-modal]'))) {
        e.preventDefault();
        modal.close(el.dataset.closeModal || el.closest('.modal'));
        return;
    }
    if (t.classList?.contains('modal') && !t.hasAttribute('data-static')) { modal.close(t); return; }
    if ((el = t.closest('[data-password-toggle]'))) {
        const input = el.parentNode.querySelector('input');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        el.setAttribute('aria-pressed', show ? 'true' : 'false');
        el.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        el.querySelector('use')?.setAttribute('href', show ? '#i-eye-off' : '#i-eye');
        return;
    }
    if ((el = t.closest('[role="tab"]')) && el.closest('[role="tablist"]') && !el.matches('a')) { selectTab(el); return; }
    if ((el = t.closest('[data-popover]'))) { e.preventDefault(); popover.toggle(el.dataset.popover, { trigger: el }); return; }
    if ((el = t.closest('[data-popover-open]'))) {
        const targetId = el.dataset.popoverOpen;
        const menu = el.closest('.menu, .popover');
        const trig = menu ? (menu._emTrigger || popTrigger(menu)) : el;
        if (menu) popover.close(menu, { focus: false });
        setTimeout(() => popover.open(targetId, { trigger: trig }), 0);
        return;
    }
    if ((el = t.closest('.menu [role^="menuitem"]')) && !el.hasAttribute('data-keep-open')) {
        popover.close(el.closest('.menu'), { focus: !el.matches('a[href]') });
    }
}

function onKeydown(e) {
    if (e.key === 'Escape' && openPops.length) {
        e.preventDefault();
        popover.close(openPops[openPops.length - 1], { focus: true });
        return;
    }
    const top = modalStack[modalStack.length - 1];
    if (top) {
        if (e.key === 'Escape' && !top.hasAttribute('data-static')) { e.preventDefault(); modal.close(top); return; }
        if (e.key === 'Tab') {
            const f = focusables(top);
            if (!f.length) { e.preventDefault(); return; }
            const first = f[0];
            const last = f[f.length - 1];
            if (!top.contains(doc.activeElement)) { e.preventDefault(); first.focus(); }
            else if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }
    const menu = e.target.closest?.('.menu[role="menu"]');
    if (menu && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
        e.preventDefault();
        const items = qsa('[role^="menuitem"]:not([aria-disabled="true"])', menu);
        const i = items.indexOf(doc.activeElement);
        const n = e.key === 'ArrowDown' ? (i + 1) % items.length : e.key === 'ArrowUp' ? (i - 1 + items.length) % items.length : e.key === 'Home' ? 0 : items.length - 1;
        focus(items[n]);
    }
    const trig = e.target.closest?.('[data-popover][aria-haspopup="menu"]');
    if (trig && e.key === 'ArrowDown') { e.preventDefault(); popover.open(trig.dataset.popover, { trigger: trig }); }
    const tab = e.target.closest?.('[role="tab"]');
    if (tab && ['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(e.key)) {
        const tabs = qsa('[role="tab"]', tab.closest('[role="tablist"]'));
        const k = tabs.indexOf(tab);
        const nx = e.key === 'ArrowRight' ? (k + 1) % tabs.length : e.key === 'ArrowLeft' ? (k - 1 + tabs.length) % tabs.length : e.key === 'Home' ? 0 : tabs.length - 1;
        e.preventDefault();
        if (tabs[nx].matches('a')) focus(tabs[nx]); else selectTab(tabs[nx], true);
    }
}

function onSubmit(e) {
    const form = e.target;
    if (!form.matches?.('form[data-validate]')) return;
    const errors = validateForm(form);
    if (errors.length) {
        e.preventDefault();
        e.stopImmediatePropagation();
        const summary = form.dataset.summary ? doc.querySelector(form.dataset.summary) : null;
        if (summary && !summary.hidden) summary.focus(); else focus(doc.getElementById(errors[0].id));
        return;
    }
    const btn = form.querySelector('[type="submit"][data-loading-label]');
    if (btn && !form.hasAttribute('hx-post')) setLoading(btn, true, btn.dataset.loadingLabel);
}

/** Initialise widgets inside a (new) subtree: combobox, toasts, modals marked to open on load. */
export function hydrate(root = doc) {
    qsa('[data-combobox]', root).forEach(combobox);
    qsa('.toast[data-duration]', root).forEach((t) => { if (!t._emArmed) { t._emArmed = true; armToast(t, +t.dataset.duration); } });
    qsa('.modal[data-open-on-load]', root).forEach((m) => { m.removeAttribute('data-open-on-load'); modal.open(m); });
}

export function initUi() {
    doc.addEventListener('click', onClick);
    doc.addEventListener('keydown', onKeydown);
    doc.addEventListener('submit', onSubmit, true);
    doc.addEventListener('input', (e) => revalidateField(e.target));
    doc.addEventListener('focusout', (e) => revalidateField(e.target));
    doc.addEventListener('em:combobox-change', (e) => {
        const trig = e.target.querySelector('.combobox__trigger');
        if (trig) revalidateField(trig);
    });
    hydrate(doc);
}
