// Truncate number inputs to 2 decimal places on every keystroke. Registered
// on the capture phase so it runs before Livewire's own wire:model listener
// reads the (still untrimmed) value off the element.
document.addEventListener('input', (e) => {
    const el = e.target;
    if (!(el instanceof HTMLInputElement) || el.type !== 'number') return;

    const match = el.value.match(/^-?\d*\.?\d{0,2}/);
    if (match && match[0] !== el.value && match[0] !== '' && match[0] !== '-') {
        el.value = match[0];
    }
}, true);

// Prevent the mouse wheel from silently changing a focused number input's
// value while the page scrolls underneath it.
document.addEventListener('wheel', (e) => {
    const el = document.activeElement;
    if (el instanceof HTMLInputElement && el.type === 'number' && el === e.target) {
        el.blur();
    }
}, { passive: true });

// Print a document PDF directly via a hidden iframe so the browser's print
// dialog targets the generated PDF. The optional onDone callback fires once the
// print dialog is dismissed, so callers can navigate away without racing it.
window.printPdfDocumentThen = function (url, onDone) {
    document.getElementById('pdf-print-frame')?.remove();

    const frame = document.createElement('iframe');
    frame.id = 'pdf-print-frame';
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    frame.src = url;

    let settled = false;
    const finish = () => {
        if (settled) return;
        settled = true;
        if (typeof onDone === 'function') onDone();
    };

    frame.addEventListener('load', () => {
        try {
            const win = frame.contentWindow;
            win.addEventListener('afterprint', finish, { once: true });
            window.addEventListener('afterprint', finish, { once: true });
            win.focus();
            win.print();
        } catch {
            window.open(url, '_blank');
            finish();
        }
    });

    document.body.appendChild(frame);
};

window.printPdfDocument = function (url) {
    window.printPdfDocumentThen(url, null);
};

window.recordDocumentPrint = function (url) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } });
};

window.printAndRecord = function (pdfUrl, recordUrl) {
    window.recordDocumentPrint(recordUrl);
    window.printPdfDocument(pdfUrl);
};

// Tag-style email input for the email modal's "Additional Recipients" field.
window.emailTagInput = function (wireRef, initialTags = [], propertyName = 'emails') {
    return {
        tags: [...initialTags],
        input: '',
        error: '',

        addTag() {
            const val = this.input.trim().replace(/,+$/, '');
            if (!val) return;
            const emails = val.split(/[\s,]+/).map(e => e.trim()).filter(Boolean);
            emails.forEach(email => {
                if (!this.isValidEmail(email)) {
                    this.error = `"${email}" is not a valid email address.`;
                    return;
                }
                if (this.tags.includes(email)) return;
                this.tags.push(email);
                this.error = '';
            });
            this.input = '';
            this.sync();
        },

        removeTag(index) {
            this.tags.splice(index, 1);
            this.sync();
        },

        onKeydown(e) {
            if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
                if (this.input.trim()) {
                    e.preventDefault();
                    this.addTag();
                }
                return;
            }
            if (e.key === 'Backspace' && !this.input && this.tags.length) {
                this.removeTag(this.tags.length - 1);
            }
        },

        onPaste(e) {
            e.preventDefault();
            this.input = (e.clipboardData || window.clipboardData).getData('text');
            this.addTag();
        },

        isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        sync() {
            wireRef.$set(propertyName, this.tags);
        },
    };
};

// After a delivery note is created we land on its page with a ?do= flag telling
// us which side-effect to run. Fires print and/or email, then strips the flag so
// a manual refresh won't re-trigger it.
window.dnRunPostCreate = function (pdfUrl, emailModal, recordPrintUrl) {
    const action = new URLSearchParams(window.location.search).get('do');
    if (!action) return;

    const doPrint = action === 'print' || action === 'emailprint';
    const doEmail = action === 'email' || action === 'emailprint';

    if (doEmail) setTimeout(() => window.Flux.modal(emailModal).show(), 100);
    if (doPrint) {
        recordPrintUrl ? window.printAndRecord(pdfUrl, recordPrintUrl) : window.printPdfDocument(pdfUrl);
    }

    window.history.replaceState({}, '', window.location.pathname);
};

document.addEventListener('alpine:init', () => {
    // ─── Global Hotkeys Store ───────────────────────────────────────
    window.Alpine.store('hotkeys', {
        showLabels: false,
        selectedRow: -1,
        rowCount: 0,
        selectedAction: -1,
        currentZone: null,      // 'search' | 'filters' | 'table' | 'pagination' | null
        zones: [],              // [{ kind, el }] in DOM order
        pendingModal: null,     // modal name awaiting y/n confirmation
        contextActions: {},     // F7/F8/F9 overrides from show pages
        tableActions: null,     // callbacks + rows set by zoneNav component
        isAdmin: false,

        init() {
            if (this._initialized) return;
            this._initialized = true;
            this.isAdmin = document.body.dataset.isAdmin === '1';
            this.showLabels = localStorage.getItem('deliverycrm_show_fkey_labels') === '1';
            window.addEventListener('keydown', (e) => this.handleKeydown(e));
        },

        handleKeydown(e) {
            const target = e.target;
            const key = e.key;

            // ── Modal confirmation mode (y/n) ──
            if (this.pendingModal) {
                if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable) {
                    return;
                }
                if (e.ctrlKey || e.metaKey || e.altKey) return;

                if (key.toLowerCase() === 'y') {
                    e.preventDefault();
                    // Find the specific modal by name, then click its submit or primary/danger button
                    const modal = document.querySelector(`[data-flux-modal="${this.pendingModal}"][data-open]`)
                        || document.querySelector(`[data-flux-modal][data-open]`);
                    if (modal) {
                        const btn = modal.querySelector('button[type="submit"]')
                            || modal.querySelector('[data-flux-button-variant="danger"]')
                            || modal.querySelector('[data-flux-button-variant="primary"]');
                        if (btn) btn.click();
                    }
                    this.pendingModal = null;
                    return;
                }
                if (key.toLowerCase() === 'n' || key === 'Escape') {
                    e.preventDefault();
                    if (window.Flux && this.pendingModal) {
                        Flux.modal(this.pendingModal).close();
                    }
                    this.pendingModal = null;
                    return;
                }
                // Block all other keys while modal is pending
                e.preventDefault();
                return;
            }

            // ── F-Keys (F1–F12) — always work, even in form fields ──
            const isFKey = key.match(/^F([1-9]|1[0-2])$/);
            if (isFKey) {
                if (e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return;
                e.preventDefault();
                const fNum = parseInt(key.substring(1));
                this.handleFKey(fNum);
                return;
            }

            // ── Special handling for the search input ──
            const isSearchInput = target.hasAttribute && target.hasAttribute('data-search-input');
            if (
                target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' ||
                target.tagName === 'SELECT' ||
                target.isContentEditable
            ) {
                if (isSearchInput) {
                    if (key === 'Tab') {
                        e.preventDefault();
                        target.blur();
                        if (e.shiftKey) this.prevZone();
                        else this.nextZone();
                        return;
                    }
                    if (key === 'ArrowDown' || key === 'Enter') {
                        e.preventDefault();
                        this.firstRow();
                        target.blur();
                        return;
                    }
                    if (key === 'Escape') {
                        e.preventDefault();
                        target.blur();
                        this.focusZone('table');
                        if (this.rowCount > 0 && this.selectedRow < 0) {
                            this.selectedRow = 0;
                        }
                        return;
                    }
                    // Note: Backspace in the search input is left to native behaviour.
                    // Don't auto-blur on empty — that surprises users by triggering
                    // the global Backspace=back handler on the next keystroke.
                }

                // Form zones: ArrowDown jumps to the next zone's first input.
                // Skip TEXTAREA so multi-line cursor movement still works.
                if (
                    key === 'ArrowDown' &&
                    target.tagName !== 'TEXTAREA' &&
                    this.currentZone &&
                    this.zones.length > 1
                ) {
                    const nextIdx = (this.zones.findIndex(z => z.kind === this.currentZone) + 1) % this.zones.length;
                    const nextZone = this.zones[nextIdx];
                    const nextInput = nextZone?.el.querySelector('input, textarea, select');
                    if (nextInput) {
                        e.preventDefault();
                        target.blur();
                        nextInput.focus();
                        this.currentZone = nextZone.kind;
                        return;
                    }
                }
                // All other inputs: let them handle keys natively
                return;
            }

            // ── Ignore if a Flux modal is open (not triggered by keyboard) ──
            if (document.querySelector('[data-flux-modal][data-open]')) {
                return;
            }

            // ── Ignore modifier keys ──
            if (e.ctrlKey || e.metaKey || e.altKey) {
                return;
            }

            const lowerKey = key.toLowerCase();

            // ── Toggle shortcut labels ──
            if (key === '\\') {
                e.preventDefault();
                this.toggleLabels();
                return;
            }

            // ── Backspace goes back ──
            if (key === 'Backspace') {
                e.preventDefault();
                const path = window.location.pathname;
                const showMatch = path.match(/^(\/[^/]+)\/\d+$/);
                if (showMatch) {
                    Livewire.navigate(showMatch[1]);
                } else {
                    window.history.back();
                }
                return;
            }

            // ── '?' opens help modal ──
            if (key === '?') {
                e.preventDefault();
                if (window.Flux) Flux.modal('hotkeys-help').show();
                return;
            }

            // ── '+' creates new on scoped list & show pages ──
            if (key === '+') {
                const path = window.location.pathname;
                const scopes = ['/customers', '/suppliers', '/delivery-notes', '/credit-notes', '/overheads', '/users', '/roles', '/supplier-invoices', '/supplier-debit-notes', '/supplier-payouts'];
                const scope = scopes.find((s) => path === s || new RegExp(`^${s}/\\d+$`).test(path));
                if (scope) {
                    e.preventDefault();
                    Livewire.navigate(`${scope}/create`);
                    return;
                }
                const addInput = document.querySelector('[data-add-input]');
                if (addInput) {
                    e.preventDefault();
                    addInput.focus();
                    if (typeof addInput.select === 'function') addInput.select();
                    return;
                }
            }

            // ── Tab / Shift+Tab: zone cycling ──
            if (key === 'Tab') {
                if (this.zones.length > 0) {
                    e.preventDefault();
                    if (e.shiftKey) {
                        this.prevZone();
                    } else {
                        this.nextZone();
                    }
                    return;
                }
                return;
            }

            // ── PageDown / PageUp: pagination ──
            if (key === 'PageDown') {
                e.preventDefault();
                const btn = document.querySelector('[data-pagination-next]');
                if (btn) {
                    btn.click();
                } else {
                    const link = document.querySelector('nav[role="navigation"] a[rel="next"]');
                    if (link) link.click();
                }
                return;
            }
            if (key === 'PageUp') {
                e.preventDefault();
                const btn = document.querySelector('[data-pagination-prev]');
                if (btn) {
                    btn.click();
                } else {
                    const link = document.querySelector('nav[role="navigation"] a[rel="prev"]');
                    if (link) link.click();
                }
                return;
            }

            // ── Filters zone navigation ──
            if (this.currentZone === 'filters') {
                const pills = Array.from(document.querySelectorAll('[data-filter-pill]'));
                const focused = document.activeElement;
                const idx = pills.indexOf(focused);

                if (key === 'ArrowRight') {
                    e.preventDefault();
                    const next = pills[idx + 1] || pills[0];
                    if (next) next.focus();
                    return;
                }
                if (key === 'ArrowLeft') {
                    e.preventDefault();
                    const prev = pills[idx - 1] || pills[pills.length - 1];
                    if (prev) prev.focus();
                    return;
                }
                if (key === 'Enter') {
                    e.preventDefault();
                    if (focused && pills.includes(focused)) focused.click();
                    return;
                }
                if (key === 'Escape') {
                    e.preventDefault();
                    this.focusZone('table');
                    return;
                }
                if (key === 'ArrowDown') {
                    e.preventDefault();
                    this.firstRow();
                    return;
                }
            }

            // ── Pagination zone navigation ──
            if (this.currentZone === 'pagination') {
                const paginationZone = this.zones.find(z => z.kind === 'pagination');
                const links = paginationZone
                    ? Array.from(paginationZone.el.querySelectorAll('a'))
                    : [];
                const focused = document.activeElement;
                const idx = links.indexOf(focused);

                if (key === 'ArrowRight') {
                    e.preventDefault();
                    const next = links[idx + 1] || links[0];
                    if (next) next.focus();
                    return;
                }
                if (key === 'ArrowLeft') {
                    e.preventDefault();
                    const prev = links[idx - 1] || links[links.length - 1];
                    if (prev) prev.focus();
                    return;
                }
                if (key === 'Enter') {
                    e.preventDefault();
                    if (focused && links.includes(focused)) focused.click();
                    return;
                }
            }

            // ── Table navigation keys (only when tableActions is active) ──
            if (this.tableActions && this.currentZone === 'table') {
                if (key === 'ArrowDown') {
                    e.preventDefault();
                    this.moveSelection(1);
                    this.selectedAction = -1;
                    return;
                }
                if (key === 'ArrowUp') {
                    e.preventDefault();
                    if (this.selectedRow === 0) {
                        // Move focus to search input if available
                        const searchInput = document.querySelector('[data-search-input]');
                        if (searchInput) {
                            searchInput.focus();
                            this.selectedRow = -1;
                            this.currentZone = 'search';
                        }
                    } else {
                        this.moveSelection(-1);
                        this.selectedAction = -1;
                    }
                    return;
                }
                if (key === 'ArrowRight') {
                    e.preventDefault();
                    this.moveAction(1);
                    return;
                }
                if (key === 'ArrowLeft') {
                    e.preventDefault();
                    this.moveAction(-1);
                    return;
                }
                if (key === 'Enter') {
                    e.preventDefault();
                    this.activateAction();
                    return;
                }
                if (key === 'Escape') {
                    e.preventDefault();
                    if (this.selectedAction >= 0) {
                        this.selectedAction = -1;
                    } else if (this.selectedRow >= 0) {
                        this.selectedRow = -1;
                    }
                    this._paintActionFocus();
                    return;
                }
            }

            // ── Table navigation without explicit zone (backward compat when tableActions registered) ──
            if (this.tableActions && this.currentZone !== 'filters' && this.currentZone !== 'pagination') {
                if (key === 'ArrowDown') {
                    e.preventDefault();
                    this.moveSelection(1);
                    this.selectedAction = -1;
                    return;
                }
                if (key === 'ArrowUp') {
                    e.preventDefault();
                    if (this.selectedRow === 0) {
                        const searchInput = document.querySelector('[data-search-input]');
                        if (searchInput) {
                            searchInput.focus();
                            this.selectedRow = -1;
                            this.currentZone = 'search';
                        }
                    } else {
                        this.moveSelection(-1);
                        this.selectedAction = -1;
                    }
                    return;
                }
                if (key === 'ArrowRight') {
                    e.preventDefault();
                    this.moveAction(1);
                    return;
                }
                if (key === 'ArrowLeft') {
                    e.preventDefault();
                    this.moveAction(-1);
                    return;
                }
                if (key === 'Enter' && this.selectedRow >= 0) {
                    e.preventDefault();
                    this.activateAction();
                    return;
                }
                if (key === 'Escape') {
                    e.preventDefault();
                    if (this.selectedAction >= 0) {
                        this.selectedAction = -1;
                    } else if (this.selectedRow >= 0) {
                        this.selectedRow = -1;
                    }
                    this._paintActionFocus();
                    return;
                }
            }

            // ── Generic zone navigation for non-list pages (form pages etc.) ──
            // When focus is on a zone wrapper itself (not an input/list table),
            // ArrowDown/ArrowUp move to the next/previous zone's first input.
            if (
                (key === 'ArrowDown' || key === 'ArrowUp') &&
                this.currentZone &&
                this.zones.length > 1 &&
                !this.tableActions &&
                this.currentZone !== 'filters' &&
                this.currentZone !== 'pagination'
            ) {
                const dir = key === 'ArrowDown' ? 1 : -1;
                const idx = this.zones.findIndex(z => z.kind === this.currentZone);
                const nextIdx = (idx + dir + this.zones.length) % this.zones.length;
                const nextZone = this.zones[nextIdx];
                const nextInput = nextZone?.el.querySelector('input, textarea, select, button');
                if (nextInput) {
                    e.preventDefault();
                    nextInput.focus();
                    this.currentZone = nextZone.kind;
                    return;
                }
            }

            // ── Gmail-style chords (g c / g d / g i / g r) ──
            if (lowerKey === 'g' && !e.shiftKey) {
                e.preventDefault();
                this._pendingChord = 'g';
                const cancel = () => { this._pendingChord = null; };
                window.addEventListener('keydown', (e2) => {
                    if (this._pendingChord !== 'g') return;
                    this._pendingChord = null;
                    e2.preventDefault();
                    const k2 = e2.key.toLowerCase();
                    if (k2 === 'c') Livewire.navigate('/customers');
                    else if (k2 === 'd') Livewire.navigate('/delivery-notes');
                    else if (k2 === 'i') Livewire.navigate('/invoices');
                    else if (k2 === 'r') Livewire.navigate('/credit-notes');
                }, { once: true });
                setTimeout(cancel, 1500);
                return;
            }

            // ── Type-to-search: single printable char focuses search input ──
            if (
                /^[a-zA-Z0-9/]$/.test(key) &&
                !e.shiftKey &&
                this.currentZone !== 'search'
            ) {
                const searchInput = document.querySelector('[data-search-input]');
                if (searchInput) {
                    e.preventDefault();
                    searchInput.focus();
                    if (key !== '/') {
                        searchInput.value += key;
                        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    this.currentZone = 'search';
                    return;
                }
            }

            // ── Context actions on show pages (e/d/p/shift+P) ──
            if (!this.tableActions) {
                if (lowerKey === 'e' && this.contextActions.edit) {
                    e.preventDefault();
                    this.contextActions.edit();
                    return;
                }
                if (lowerKey === 'd' && this.contextActions.delete) {
                    e.preventDefault();
                    this.contextActions.delete();
                    return;
                }
                if (key === 'P' && e.shiftKey && this.contextActions.downloadPdf) {
                    e.preventDefault();
                    this.contextActions.downloadPdf();
                    return;
                }
                if (lowerKey === 'p' && !e.shiftKey && this.contextActions.viewPdf) {
                    e.preventDefault();
                    this.contextActions.viewPdf();
                    return;
                }
            }
        },

        handleFKey(num) {
            switch (num) {
                case 1: Livewire.navigate('/customers/create'); break;
                case 2: {
                    const customPanel = document.activeElement?.closest?.('[data-f2-handler]');
                    if (customPanel) { customPanel.dispatchEvent(new CustomEvent('f2-action', { bubbles: false })); break; }
                    const submitBtn = document.querySelector('[data-form-submit]');
                    if (submitBtn) { submitBtn.click(); break; }
                    const form = Array.from(document.querySelectorAll('form'))
                        .find((f) => f.offsetParent !== null);
                    if (form) {
                        if (typeof form.requestSubmit === 'function') form.requestSubmit();
                        else form.querySelector('button[type="submit"]')?.click();
                    } else {
                        Livewire.navigate('/delivery-notes/create');
                    }
                    break;
                }
                case 3: Livewire.navigate('/dashboard'); break;
                case 4: Livewire.navigate('/customers'); break;
                case 5: Livewire.navigate('/delivery-notes'); break;
                case 6: Livewire.navigate('/invoices'); break;
                case 7:
                    if (this.contextActions.f7) this.contextActions.f7();
                    break;
                case 8:
                    if (this.contextActions.f8) this.contextActions.f8();
                    break;
                case 9: window.dispatchEvent(new CustomEvent('fkey-add-note')); break;
                case 10: Livewire.navigate('/settings/profile'); break;
                case 11:
                    if (this.isAdmin) Livewire.navigate('/settings/crm');
                    break;
                case 12:
                    const form = document.getElementById('logout-form');
                    if (form) form.submit();
                    break;
            }
        },

        moveSelection(delta) {
            if (this.rowCount === 0) return;
            let next = this.selectedRow + delta;
            if (next < 0) next = 0;
            if (next >= this.rowCount) next = this.rowCount - 1;
            this.selectedRow = next;
            this.scrollSelectedIntoView();
            this._paintActionFocus();
        },

        scrollSelectedIntoView() {
            const idx = this.selectedRow;
            requestAnimationFrame(() => {
                const row = document.querySelector(`tr[data-row-index="${idx}"]`);
                if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        },

        moveAction(delta) {
            if (!this.tableActions || this.selectedRow < 0) return;
            const row = this.tableActions.rows?.[this.selectedRow];
            if (!row || !row.actions || row.actions.length === 0) return;
            const maxIdx = row.actions.length - 1;
            let next = this.selectedAction + delta;
            // First press of → from -1 should land on first action (0)
            if (next < 0) next = 0;
            if (next > maxIdx) next = maxIdx;
            this.selectedAction = next;
            this._paintActionFocus();
        },

        _paintActionFocus() {
            if (!this.tableActions?.rows) return;
            const focusClasses = ['!ring-2', '!ring-indigo-500', 'ring-offset-1', 'dark:ring-offset-zinc-900', 'rounded-md'];
            this.tableActions.rows.forEach((row, rowIdx) => {
                row.actions?.forEach((a, actionIdx) => {
                    const focused = rowIdx === this.selectedRow && actionIdx === this.selectedAction;
                    if (focused) a.el.classList.add(...focusClasses);
                    else a.el.classList.remove(...focusClasses);
                });
            });
        },

        activateAction() {
            if (this.selectedRow < 0) return;
            const row = this.tableActions?.rows?.[this.selectedRow];
            if (!row) return;
            if (this.selectedAction >= 0 && row.actions[this.selectedAction]) {
                row.actions[this.selectedAction].el.click();
            } else {
                this.tableActions.view(this.selectedRow);
            }
        },

        firstRow() {
            if (!this.tableActions || this.rowCount === 0) return;
            const tableZone = this.zones.find(z => z.kind === 'table');
            if (tableZone) {
                tableZone.el.focus({ preventScroll: true });
                this.currentZone = 'table';
            }
            this.selectedRow = 0;
            this.selectedAction = -1;
            this.scrollSelectedIntoView();
            this._paintActionFocus();
        },

        clearActionFocus() {
            this.selectedAction = -1;
            this._paintActionFocus();
        },

        registerZone(el, kind) {
            // Remove existing registration for this element
            this.zones = this.zones.filter(z => z.el !== el);
            // Insert in DOM order
            const allZoneEls = Array.from(document.querySelectorAll('[data-zone]'));
            const insertIdx = allZoneEls.indexOf(el);
            if (insertIdx === -1) {
                this.zones.push({ el, kind });
            } else {
                // Find correct sorted position
                let pos = this.zones.length;
                for (let i = 0; i < this.zones.length; i++) {
                    const existingIdx = allZoneEls.indexOf(this.zones[i].el);
                    if (existingIdx > insertIdx) {
                        pos = i;
                        break;
                    }
                }
                this.zones.splice(pos, 0, { el, kind });
            }
        },

        unregisterZone(el) {
            this.zones = this.zones.filter(z => z.el !== el);
            if (this.zones.length === 0) {
                this.currentZone = null;
            }
        },

        focusZone(kind) {
            const zone = this.zones.find(z => z.kind === kind);
            if (zone) {
                zone.el.focus({ preventScroll: true });
                this.currentZone = kind;
            }
        },

        nextZone() {
            if (this.zones.length === 0) return;
            const currentIdx = this.zones.findIndex(z => z.kind === this.currentZone);
            const nextIdx = (currentIdx + 1) % this.zones.length;
            const nextZone = this.zones[nextIdx];
            nextZone.el.focus({ preventScroll: true });
            this.currentZone = nextZone.kind;
        },

        prevZone() {
            if (this.zones.length === 0) return;
            const currentIdx = this.zones.findIndex(z => z.kind === this.currentZone);
            const prevIdx = (currentIdx - 1 + this.zones.length) % this.zones.length;
            const prevZone = this.zones[prevIdx];
            prevZone.el.focus({ preventScroll: true });
            this.currentZone = prevZone.kind;
        },

        toggleLabels() {
            this.showLabels = !this.showLabels;
            localStorage.setItem('deliverycrm_show_fkey_labels', this.showLabels ? '1' : '0');
        },

        registerContext(actions) {
            this.contextActions = actions;
        },

        clearContext() {
            this.contextActions = {};
        },

        registerTable(count, actions) {
            this.rowCount = count;
            this.tableActions = actions;
            this.selectedRow = -1;
            this.selectedAction = -1;
        },

        clearTable() {
            this.rowCount = 0;
            this.tableActions = null;
            this.selectedRow = -1;
            this.selectedAction = -1;
        },

        openModalWithConfirm(modalName) {
            if (window.Flux) {
                Flux.modal(modalName).show();
                this.pendingModal = modalName;
            }
        },
    });

    // ─── Zone Navigation Component ──────────────────────────────────
    window.Alpine.data('zoneNav', (kind = 'table') => ({
        _zoneRows: [],

        init() {
            // Make the element programmatically focusable
            if (!this.$el.hasAttribute('tabindex')) {
                this.$el.setAttribute('tabindex', '-1');
            }
            // Mark with data-zone for DOM-order sorting
            if (!this.$el.hasAttribute('data-zone')) {
                this.$el.setAttribute('data-zone', kind);
            }
            Alpine.store('hotkeys').registerZone(this.$el, kind);
            // focusin bubbles, so it fires whether the zone wrapper itself
            // or any descendant input/button gets focus.
            this.$el.addEventListener('focusin', () => {
                Alpine.store('hotkeys').currentZone = kind;
            });

            if (kind === 'table') {
                this.scanRows();
                Alpine.store('hotkeys').registerTable(this._zoneRows.length, {
                    rows: this._zoneRows,
                    view: (i) => this.viewRow(i),
                    edit: (i) => this.editRow(i),
                    email: (i) => this.emailRow(i),
                    convert: (i) => this.convertRow(i),
                    delete: (i) => this.deleteRow(i),
                });

                // Re-scan when DOM changes (pagination, search, Livewire morph)
                this._observer = new MutationObserver(() => {
                    if (!this.$el?.isConnected) return;
                    this.scanRows();
                    const store = Alpine.store('hotkeys');
                    store.rowCount = this._zoneRows.length;
                    // Update the rows reference in tableActions
                    if (store.tableActions) {
                        store.tableActions.rows = this._zoneRows;
                    }
                    if (store.selectedRow >= this._zoneRows.length) {
                        store.selectedRow = -1;
                        store.selectedAction = -1;
                    }
                });
                const tbody = this.$el.querySelector('tbody');
                if (tbody) {
                    this._observer.observe(tbody, { childList: true, subtree: true });
                }
            }
        },

        destroy() {
            Alpine.store('hotkeys').unregisterZone(this.$el);
            if (kind === 'table') {
                Alpine.store('hotkeys').clearTable();
                if (this._observer) {
                    this._observer.disconnect();
                }
            }
        },

        scanRows() {
            const trs = this.$el.querySelectorAll('tr[data-row-index]');
            this._zoneRows = Array.from(trs).map((tr) => ({
                viewUrl: tr.dataset.viewUrl || null,
                editUrl: tr.dataset.editUrl || null,
                emailModal: tr.dataset.emailModal || null,
                convertModal: tr.dataset.convertModal || null,
                convertId: tr.dataset.convertId ? Number(tr.dataset.convertId) : null,
                deleteModal: tr.dataset.deleteModal || null,
                actions: Array.from(tr.querySelectorAll('[data-row-action]')).map((el) => ({
                    kind: el.dataset.rowAction,
                    el,
                })),
            }));
        },

        viewRow(i) {
            const row = this._zoneRows[i];
            if (row?.viewUrl) Livewire.navigate(row.viewUrl);
        },

        editRow(i) {
            const row = this._zoneRows[i];
            if (row?.editUrl) Livewire.navigate(row.editUrl);
        },

        emailRow(i) {
            const row = this._zoneRows[i];
            const trigger = row?.actions?.find((a) => a.kind === 'email')?.el;
            if (trigger) trigger.click();
        },

        convertRow(i) {
            const row = this._zoneRows[i];
            if (!row?.convertModal) return;
            const trigger = row.actions?.find((a) => a.kind === 'convert')?.el;
            if (trigger) {
                trigger.click();
            } else {
                Alpine.store('hotkeys').openModalWithConfirm(row.convertModal);
            }
        },

        deleteRow(i) {
            const row = this._zoneRows[i];
            if (row?.deleteModal) {
                Alpine.store('hotkeys').openModalWithConfirm(row.deleteModal);
            }
        },
    }));

    // ─── Legacy tableNav alias (backward compatibility) ─────────────
    window.Alpine.data('tableNav', () => Alpine.data('zoneNav')('table'));

    // ─── Ledger Table (Transaction History row expand/keyboard nav) ──
    // Self-contained: does not use Alpine.store('hotkeys') or zoneNav.
    window.Alpine.data('ledgerTable', () => ({
        selectedIndex: -1,
        openKey: null,
        openLinkedRef: null,

        get rows() {
            return Array.from(this.$el.querySelectorAll('tr[data-row-index]'));
        },

        onKeydown(e) {
            const key = e.key;
            if (key === 'ArrowDown') {
                e.preventDefault();
                this.move(1);
            } else if (key === 'ArrowUp') {
                e.preventDefault();
                this.move(-1);
            } else if (key === 'Enter') {
                e.preventDefault();
                this.toggleSelected();
            } else if (key === 'Escape') {
                e.preventDefault();
                this.handleEscape();
            }
        },

        move(delta) {
            const rows = this.rows;
            if (rows.length === 0) return;
            let next = this.selectedIndex + delta;
            if (next < 0) next = 0;
            if (next >= rows.length) next = rows.length - 1;
            this.selectedIndex = next;
            const row = rows[next];
            if (row) {
                row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        },

        toggleSelected() {
            const rows = this.rows;
            const row = rows[this.selectedIndex];
            if (!row) return;
            this.toggle(this.selectedIndex, row.dataset.rowKey);
        },

        selectRow(index, key) {
            this.selectedIndex = index;
            this.toggle(index, key, { forceSelect: true });
        },

        toggle(index, key, { forceSelect = false } = {}) {
            if (!forceSelect) {
                this.selectedIndex = index;
            }
            if (this.openKey === key) {
                this.openKey = null;
            } else {
                this.openKey = key;
            }
            this.openLinkedRef = null;
        },

        handleEscape() {
            if (this.openLinkedRef !== null) {
                this.openLinkedRef = null;
            } else if (this.openKey !== null) {
                this.openKey = null;
            } else {
                this.selectedIndex = -1;
            }
        },
    }));

    // ─── Line-Item Form Component (DN/Invoice create/edit) ─────────
    window.Alpine.data('lineItemForm', (initialRows = [], units = [], fallback = '/', defaults = {}) => ({
        rows: initialRows,
        units,
        fallback,
        invoiceSuggestions: [],
        thOpen: false,
        thSearch: '',
        thActiveIdx: -1,
        thRowIdx: null,
        thPosition: { top: 0, left: 0, width: 0 },
        _lineDefault: { details: '', quantity: '', price: '', per: '', is_note: false, ...(defaults.line || {}) },
        _noteDefault: { details: '', quantity: '0', price: '', per: '', is_note: true, ...(defaults.note || {}) },

        init() {
            this.$nextTick(() => {
                this.$el.querySelector('input:not([type=hidden]):not([type=file]), textarea, select')?.focus();
            });

            if (this.$wire) {
                this.invoiceSuggestions = this.$wire.invoiceItemSuggestions ?? [];
                this.$wire.$watch('invoiceItemSuggestions', (v) => { this.invoiceSuggestions = v ?? []; });
            }

            this._reposition = () => {
                if (this.thOpen && this.thRowIdx !== null) this._thUpdatePosition(this.thRowIdx);
            };
            window.addEventListener('scroll', this._reposition, true);
            window.addEventListener('resize', this._reposition);

            this.$el.addEventListener('change', (e) => {
                if (e.target.tagName !== 'SELECT') return;
                const tr = e.target.closest('tr[data-row-idx]');
                if (!tr) {
                    this.moveFormField(e.target, 'linear', 1);
                    return;
                }
                const rowIdx = parseInt(tr.dataset.rowIdx, 10);
                const nextTr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${rowIdx + 1}"]`);
                if (nextTr) {
                    this._focusWithRetry(nextTr.querySelector('input[data-row-details]'));
                } else {
                    this.add();
                }
            });
        },

        // A closing native <select> popup hands focus back to the select after
        // our handler runs, so a single focus() can lose the race. Retry until
        // the target actually holds focus.
        _focusWithRetry(target, tries = 12) {
            if (!target) return;
            const attempt = () => {
                target.focus();
                if (typeof target.select === 'function' && target.type !== 'number' && target.type !== 'date') target.select();
                if (document.activeElement !== target && tries-- > 0) {
                    setTimeout(attempt, 25);
                }
            };
            setTimeout(attempt, 0);
        },

        focusFirstItem() {
            this._focusWithRetry(this.$refs.rowsBody?.querySelector('input[data-row-details]'));
        },

        fillRow(i, item) {
            Object.assign(this.rows[i], item);
            this.$nextTick(() => {
                const tr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${i}"]`);
                const qty = tr?.querySelector('input[data-row-qty]');
                if (qty) {
                    qty.focus();
                    if (typeof qty.select === 'function') qty.select();
                }
            });
        },

        add() {
            this.rows.push({ ...this._lineDefault });
            this.$nextTick(() => this.focusLast());
        },

        addNote() {
            this.rows.push({ ...this._noteDefault });
            this.$nextTick(() => this.focusLast());
        },

        remove(i) {
            if (this.rows.length > 1) this.rows.splice(i, 1);
        },

        removeFocused(target) {
            const tr = target?.closest('tr[data-row-idx]');
            if (!tr) return;
            const idx = parseInt(tr.dataset.rowIdx, 10);
            if (!Number.isInteger(idx)) return;
            if (this.rows.length <= 1) return;
            const rowInputs = Array.from(tr.querySelectorAll('input'));
            const colIdx = Math.max(0, rowInputs.indexOf(target));
            const nextIdx = idx === this.rows.length - 1 ? idx - 1 : idx;
            this.remove(idx);
            this.$nextTick(() => {
                const nextTr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${nextIdx}"]`);
                if (!nextTr) return;
                const inputs = Array.from(nextTr.querySelectorAll('input'));
                const next = inputs[colIdx] ?? inputs[0];
                if (next) {
                    next.focus();
                    if (typeof next.select === 'function') next.select();
                }
            });
        },

        insertAt(target, offset) {
            const tr = target?.closest('tr[data-row-idx]');
            if (!tr) return;
            const idx = parseInt(tr.dataset.rowIdx, 10);
            if (!Number.isInteger(idx)) return;
            const rowInputs = Array.from(tr.querySelectorAll('input'));
            const colIdx = Math.max(0, rowInputs.indexOf(target));
            const at = idx + offset;
            this.rows.splice(at, 0, { ...this._lineDefault });
            this.$nextTick(() => {
                const newTr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${at}"]`);
                if (!newTr) return;
                const inputs = Array.from(newTr.querySelectorAll('input'));
                const next = inputs[colIdx] ?? inputs[0];
                if (next) {
                    next.focus();
                    if (typeof next.select === 'function') next.select();
                    next.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            });
        },

        focusRowDetails(idx) {
            const tr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${idx}"]`);
            const input = tr?.querySelector('input[data-row-details]');
            if (input) {
                input.focus();
                input.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        },

        _rowCells: 'input, select, button[data-row-remove]:not([hidden]):not([style*="display: none"])',

        moveVertical(target, dir) {
            const cell = target.closest('input, select, button[data-row-remove]') ?? target;
            const tr = cell.closest('tr[data-row-idx]');
            if (!tr) return;
            const rowIdx = parseInt(tr.dataset.rowIdx, 10);
            const rowCells = Array.from(tr.querySelectorAll(this._rowCells));
            const colIdx = rowCells.indexOf(cell);
            const targetTr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${rowIdx + dir}"]`);
            if (!targetTr) {
                this.moveFormField(cell, 'y', dir);
                return;
            }
            const targetCells = Array.from(targetTr.querySelectorAll(this._rowCells));
            const next = targetCells[colIdx] ?? targetCells[0];
            if (next) this._focusField(next);
        },

        moveHorizontal(target, dir) {
            const cell = target.closest('input, select, button[data-row-remove]') ?? target;
            const cells = Array.from(this.$refs.rowsBody?.querySelectorAll(this._rowCells) ?? []);
            const idx = cells.indexOf(cell);
            if (idx < 0) return;
            const next = cells[idx + dir];
            if (next) this._focusField(next);
        },

        atTextBoundary(input, dir) {
            // Anything that isn't a regular text-bearing input (number, date, switch, focusable card)
            // has no cursor to honor — treat as always-at-boundary so arrow keys move focus.
            if (input.type === 'number') return true;
            if (typeof input.value !== 'string') return true;
            try {
                const start = input.selectionStart;
                const end = input.selectionEnd;
                if (start === null || end === null || start === undefined || end === undefined) return true;
                if (dir > 0) return start === input.value.length && end === input.value.length;
                return start === 0 && end === 0;
            } catch (_) {
                return true;
            }
        },

        focusLast() {
            const inputs = this.$refs.rowsBody?.querySelectorAll('input[data-row-details]');
            if (!inputs || !inputs.length) return;
            const last = inputs[inputs.length - 1];
            last.focus();
            // Sticky save-bar overlays the bottom of the page, so the new row
            // can be technically "in view" but visually obscured. Force-center it.
            last.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },

        lineValue(row) {
            const q = Number(row.quantity || 0);
            const p = Number(row.price || 0);
            const per = String(row.per ?? '').trim();
            if (per.toLowerCase() === 'lot') {
                return p;
            }
            const n = Number(per);
            if (per !== '' && Number.isFinite(n) && n > 0) {
                return (q / n) * p;
            }
            return q * p;
        },

        netValue(row) {
            const v = this.lineValue(row);
            return row.discount_percent ? v * (row.discount_percent / 100) : v;
        },

        importRows(items) {
            this.rows = items;
            this.$nextTick(() => {
                items.forEach((row, i) => {
                    if (!row.per) return;
                    const tr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${i}"]`);
                    const sel = tr?.querySelector('select');
                    if (sel) sel.value = row.per;
                });
            });
        },

        get thFiltered() {
            const q = this.thSearch.toLowerCase();
            return this.invoiceSuggestions.filter((s) => {
                if (q && !s.details.toLowerCase().includes(q)) return false;
                return !this.rows.some((r, idx) =>
                    idx !== this.thRowIdx && r.details === s.details && r.from_invoice === true
                );
            });
        },

        get thGhostSuffix() {
            if (!this.thSearch || !this.thFiltered.length) return '';
            const first = this.thFiltered[0].details;
            if (first.toLowerCase().startsWith(this.thSearch.toLowerCase())) {
                return first.slice(this.thSearch.length);
            }
            return '';
        },

        _thUpdatePosition(i) {
            const tr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${i}"]`);
            const input = tr?.querySelector('input[data-row-details]');
            if (!input) return;
            const rect = input.getBoundingClientRect();
            this.thPosition = { top: rect.bottom, left: rect.left, width: rect.width };
        },

        thFocus(i) {
            this.thRowIdx = i;
            this.thSearch = this.rows[i]?.details ?? '';
            this.thActiveIdx = -1;
            this._thUpdatePosition(i);
            this.$nextTick(() => { this.thOpen = this.thFiltered.length > 0; });
        },

        thInput(value, i) {
            this.thRowIdx = i;
            this.thSearch = value;
            this.thActiveIdx = -1;
            this._thUpdatePosition(i);
            this.thOpen = this.thFiltered.length > 0 && value.length > 0;
        },

        thKeydown(e, i) {
            if (!this.thOpen || this.thRowIdx !== i) return;
            if (e.key === 'ArrowDown') {
                this.thActiveIdx = Math.min(this.thActiveIdx + 1, this.thFiltered.length - 1);
                e.preventDefault(); e.stopPropagation();
            } else if (e.key === 'ArrowUp') {
                this.thActiveIdx = Math.max(this.thActiveIdx - 1, 0);
                e.preventDefault(); e.stopPropagation();
            } else if (e.key === 'Enter' && this.thOpen) {
                const toPick = this.thActiveIdx >= 0 ? this.thFiltered[this.thActiveIdx] : (this.thGhostSuffix ? this.thFiltered[0] : null);
                if (toPick) { this.thPick(toPick); e.preventDefault(); e.stopPropagation(); }
            } else if (e.key === 'Escape') {
                this.thOpen = false; this.thActiveIdx = -1;
            }
        },

        thPick(s) {
            const i = this.thRowIdx;
            Object.assign(this.rows[i], s);
            this.thOpen = false;
            this.thSearch = s.details;
            this.thActiveIdx = -1;
            this.$nextTick(() => {
                const tr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${i}"]`);
                const qty = tr?.querySelector('input[data-row-qty]');
                if (qty) { qty.focus(); if (typeof qty.select === 'function') qty.select(); }
            });
        },

        thBlur() {
            setTimeout(() => { this.thOpen = false; }, 150);
        },

        submit() {
            this.$wire.set('items', this.rows, false);
            this.$wire.save();
        },

        submitAndEmail() {
            this.$wire.set('items', this.rows, false);
            this.$wire.saveAndEmail();
        },

        cancel() {
            if (window.history.length > 1) window.history.back();
            else Livewire.navigate(this.fallback);
        },

        confirmExit() {
            if (window.Flux) {
                Flux.modal('exit-confirm').show();
            } else {
                this.cancel();
            }
        },

        handleKey(e) {
            const tag = e.target.tagName;
            const isFormInput = (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA')
                || e.target.hasAttribute?.('data-form-stop')
                || e.target.hasAttribute?.('data-flux-switch')
                || e.target.hasAttribute?.('data-form-nav')
                || e.target.hasAttribute?.('data-row-remove')
                || e.target.closest?.('[data-row-remove]');
            const inItems = isFormInput && e.target.closest?.('[data-items-table]');
            const ctrl = e.ctrlKey || e.metaKey;
            const stop = () => { e.preventDefault(); e.stopPropagation(); };

            if (ctrl && e.key === 'Enter')                       { e.preventDefault(); this.submit(); return; }
            if (e.key === 'Escape')                              { e.preventDefault(); this.confirmExit(); return; }
            if (e.key === 'Insert' && !inItems)                  { stop(); this.add(); return; }

            if (inItems) {
                if (e.key === 'Enter' && !ctrl)       { stop(); this.advanceFromItem(e.target); return; }
                if (ctrl && e.key === 'Backspace')                   { stop(); this.removeFocused(e.target); return; }
                if (ctrl && e.key === 'Delete')                      { stop(); this.removeFocused(e.target); return; }
                if (ctrl && e.key === 'Insert')                      { stop(); this.insertAt(e.target, 1); return; }
                if (e.key === 'Insert')                              { stop(); this.insertAt(e.target, 0); return; }
                if (e.key === 'ArrowDown')                           { stop(); this.moveVertical(e.target, 1); return; }
                if (e.key === 'ArrowUp')                             { stop(); this.moveVertical(e.target, -1); return; }
                if (e.key === 'ArrowRight' && this.atTextBoundary(e.target, 1))  { stop(); this.moveHorizontal(e.target, 1); return; }
                if (e.key === 'ArrowLeft' && this.atTextBoundary(e.target, -1))  { stop(); this.moveHorizontal(e.target, -1); return; }
                return;
            }

            if (isFormInput) {
                if (e.key === 'Enter' && !ctrl && tag !== 'TEXTAREA') { stop(); this.moveFormField(e.target, 'linear', 1); return; }
                if (e.key === 'ArrowDown')                           { stop(); this.moveFormField(e.target, 'y', 1); return; }
                if (e.key === 'ArrowUp')                             { stop(); this.moveFormField(e.target, 'y', -1); return; }
                if (e.key === 'ArrowRight' && this.atTextBoundary(e.target, 1))  { stop(); this.moveFormField(e.target, 'x', 1); return; }
                if (e.key === 'ArrowLeft' && this.atTextBoundary(e.target, -1))  { stop(); this.moveFormField(e.target, 'x', -1); return; }
            }
        },

        unitGhostMatch(typed) {
            const t = String(typed ?? '').trim();
            if (!t) return '';
            const lower = t.toLowerCase();
            const hit = (this.units || []).find((u) => {
                const s = String(u);
                return s.toLowerCase().startsWith(lower) && s.toLowerCase() !== lower;
            });
            return hit ?? '';
        },

        unitGhostSuffix(typed) {
            const match = this.unitGhostMatch(typed);
            if (!match) return '';
            return match.substring(String(typed ?? '').length);
        },

        advanceFromItem(target) {
            const cell = target.closest('input, button[data-row-remove]') ?? target;
            const tr = cell.closest('tr[data-row-idx]');
            if (!tr) return;
            const rowIdx = parseInt(tr.dataset.rowIdx, 10);

            const cells = Array.from(tr.querySelectorAll(this._rowCells));
            const colIdx = cells.indexOf(cell);
            const nextSibling = cells[colIdx + 1];
            if (nextSibling) {
                this._focusField(nextSibling);
                return;
            }
            const nextTr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${rowIdx + 1}"]`);
            if (nextTr) {
                const first = nextTr.querySelector(this._rowCells);
                if (first) this._focusField(first);
                return;
            }
            this.add();
        },

        _formFieldSelector: 'input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [data-flux-switch], [data-form-stop], button[data-form-nav]:not([disabled])',

        _focusField(el) {
            if (!el) return;
            el.focus();
            if (typeof el.select === 'function' && el.type !== 'number' && el.type !== 'date') el.select();
            if (el.tagName === 'SELECT' && el.closest('[data-items-table]') && typeof el.showPicker === 'function') {
                try { el.showPicker(); } catch (_) {}
            }
        },

        moveFormField(input, axis, dir) {
            if (axis === 'linear') {
                const all = Array.from(this.$el.querySelectorAll(this._formFieldSelector))
                    .filter((el) => el.offsetParent !== null);
                const i = all.indexOf(input);
                if (i < 0) return;
                this._focusField(all[i + dir]);
                return;
            }

            const itemsTable = input.closest('[data-items-table]');
            if (itemsTable) {
                // Exiting items table — walk linearly to nearest focusable outside it.
                const all = Array.from(this.$el.querySelectorAll(this._formFieldSelector))
                    .filter((el) => el.offsetParent !== null && !itemsTable.contains(el));
                const target = dir > 0
                    ? all.find((el) => itemsTable.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)
                    : [...all].reverse().find((el) => itemsTable.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_PRECEDING);
                this._focusField(target);
                return;
            }

            const grid = input.closest('[data-form-grid]');
            if (grid) {
                // Read column count from the grid's computed style (respects responsive classes).
                const cols = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length;
                // Direct grid children that hold our focusable fields, in order.
                const cells = Array.from(grid.children).filter((c) => c.querySelector(this._formFieldSelector));
                let cell = input;
                while (cell.parentElement !== grid) cell = cell.parentElement;
                const idx = cells.indexOf(cell);
                if (idx < 0) return;

                // Compute (row, col, span) for each cell using col-span-N classes.
                const spanFor = (c) => {
                    const cs = getComputedStyle(c).gridColumnEnd;
                    const m = /span (\d+)/.exec(cs);
                    return m ? Math.min(parseInt(m[1], 10), cols) : 1;
                };
                const layout = [];
                let r = 0, col = 0;
                for (const c of cells) {
                    const span = spanFor(c);
                    if (col + span > cols) { r++; col = 0; }
                    layout.push({ row: r, col, span });
                    col += span;
                    if (col >= cols) { r++; col = 0; }
                }

                const cur = layout[idx];
                let targetIdx = -1;
                if (axis === 'y') {
                    const targetRow = cur.row + dir;
                    targetIdx = layout.findIndex((p) =>
                        p.row === targetRow &&
                        p.col + p.span > cur.col &&
                        p.col < cur.col + cur.span
                    );
                } else {
                    targetIdx = idx + dir;
                    if (targetIdx < 0 || targetIdx >= cells.length) targetIdx = -1;
                }

                if (targetIdx >= 0) {
                    this._focusField(cells[targetIdx].querySelector(this._formFieldSelector));
                    return;
                }
                // No target in this grid — fall through to linear walk across grids.
            }

            // Linear DOM-order walk between separate sections (or single-column mobile).
            const all = Array.from(this.$el.querySelectorAll(this._formFieldSelector))
                .filter((el) => el.offsetParent !== null);
            const i = all.indexOf(input);
            if (i < 0) return;
            this._focusField(all[i + dir]);
        },

        destroy() {
            if (this._reposition) {
                window.removeEventListener('scroll', this._reposition, true);
                window.removeEventListener('resize', this._reposition);
            }
        },
    }));

    // ─── Supplier Invoice Line Form Component ───────────────────
    window.Alpine.data('supplierInvoiceLineForm', (initialRows = [], vatRate = 20) => ({
        rows: initialRows.length ? initialRows : [{ product_code: '', quantity: 1, unit_amount: '', vat_applicable: true }],
        vatRate,
        _rowDefault: { product_code: '', quantity: 1, unit_amount: '', vat_applicable: true },
        _rowCells: 'input, select',

        init() {
            this.$nextTick(() => {
                this.$el.querySelector('input:not([type=hidden])')?.focus();
            });
        },

        lineNet(row) {
            return Math.round((parseFloat(row.quantity) || 0) * (parseFloat(row.unit_amount) || 0) * 100) / 100;
        },

        lineGross(row) {
            const net = this.lineNet(row);
            return row.vat_applicable ? Math.round((net + net * this.vatRate / 100) * 100) / 100 : net;
        },

        get netTotal() {
            return Math.round(this.rows.reduce((s, r) => s + this.lineNet(r), 0) * 100) / 100;
        },

        get vatTotal() {
            return Math.round(
                this.rows
                    .filter(r => r.vat_applicable)
                    .reduce((s, r) => s + this.lineNet(r) * this.vatRate / 100, 0)
                * 100) / 100;
        },

        get grossTotal() {
            return Math.round((this.netTotal + this.vatTotal) * 100) / 100;
        },

        get discountPct() {
            return parseFloat(this.$wire.tradeDiscount) || 0;
        },

        get discountAmount() {
            const base = this.$wire.discountOnGross ? this.grossTotal : this.netTotal;
            return Math.round(base * this.discountPct / 100 * 100) / 100;
        },

        get payableTotal() {
            return Math.max(0, Math.round((this.grossTotal - this.discountAmount) * 100) / 100);
        },

        add() {
            this.rows.push({ ...this._rowDefault });
            this.$nextTick(() => this.focusLast());
        },

        remove(i) {
            if (this.rows.length > 1) this.rows.splice(i, 1);
        },

        focusLast() {
            const inputs = this.$el.querySelectorAll('input[data-row-details]');
            if (!inputs || !inputs.length) return;
            const last = inputs[inputs.length - 1];
            last.focus();
            last.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },

        submit() {
            this.$wire.set('items', this.rows, false);
            this.$wire.save();
        },

        _focusField(el) {
            if (!el) return;
            el.focus();
            if (typeof el.select === 'function' && el.type !== 'number') el.select();
            if (el.tagName === 'SELECT' && typeof el.showPicker === 'function') {
                try { el.showPicker(); } catch (_) {}
            }
        },

        atTextBoundary(input, dir) {
            if (input.type === 'number') return true;
            if (typeof input.value !== 'string') return true;
            try {
                const start = input.selectionStart;
                const end = input.selectionEnd;
                if (start === null || end === null) return true;
                if (dir > 0) return start === input.value.length && end === input.value.length;
                return start === 0 && end === 0;
            } catch (_) { return true; }
        },

        moveVertical(target, dir) {
            const cell = target.closest('input, select, button[data-row-remove]') ?? target;
            const tr = cell.closest('tr[data-row-idx]');
            if (!tr) return;
            const rowIdx = parseInt(tr.dataset.rowIdx, 10);
            const rowCells = Array.from(tr.querySelectorAll(this._rowCells));
            const colIdx = rowCells.indexOf(cell);
            const tbody = this.$el.querySelector('tbody');
            const targetTr = tbody?.querySelector(`tr[data-row-idx="${rowIdx + dir}"]`);
            if (!targetTr) return;
            const targetCells = Array.from(targetTr.querySelectorAll(this._rowCells));
            const next = targetCells[colIdx] ?? targetCells[0];
            if (next) this._focusField(next);
        },

        moveHorizontal(target, dir) {
            const cell = target.closest('input, select, button[data-row-remove]') ?? target;
            const tbody = this.$el.querySelector('tbody');
            const cells = Array.from(tbody?.querySelectorAll(this._rowCells) ?? []);
            const idx = cells.indexOf(cell);
            if (idx < 0) return;
            const next = cells[idx + dir];
            if (next) this._focusField(next);
        },

        advanceFromItem(target) {
            const cell = target.closest('input, select, button[data-row-remove]') ?? target;
            const tr = cell.closest('tr[data-row-idx]');
            if (!tr) return;
            const rowIdx = parseInt(tr.dataset.rowIdx, 10);
            const cells = Array.from(tr.querySelectorAll(this._rowCells));
            const colIdx = cells.indexOf(cell);
            const nextSibling = cells[colIdx + 1];
            if (nextSibling) { this._focusField(nextSibling); return; }
            const tbody = this.$el.querySelector('tbody');
            const nextTr = tbody?.querySelector(`tr[data-row-idx="${rowIdx + 1}"]`);
            if (nextTr) {
                const first = nextTr.querySelector(this._rowCells);
                if (first) { this._focusField(first); return; }
            }
            this.add();
        },

        _advanceFormField(el) {
            const focusables = Array.from(this.$el.querySelectorAll(
                'input:not([type=hidden]):not([type=file]):not([disabled]), select:not([disabled])'
            )).filter(f => f.offsetParent !== null);
            const idx = focusables.indexOf(el);
            const next = focusables[idx + 1];
            if (next) this._focusField(next);
        },

        handleKey(e) {
            const tag = e.target.tagName;
            const isInput = tag === 'INPUT' || tag === 'SELECT';
            const inItems = isInput && e.target.closest?.('[data-items-table]');
            const ctrl = e.ctrlKey || e.metaKey;
            const stop = () => { e.preventDefault(); e.stopPropagation(); };

            if (ctrl && e.key === 'Enter') { e.preventDefault(); this.submit(); return; }
            if (e.key === 'Escape') { e.preventDefault(); if (window.Flux) Flux.modal('exit-confirm').show(); return; }

            if (inItems) {
                if (e.key === 'Enter' && !ctrl)           { stop(); this.advanceFromItem(e.target); return; }
                if (ctrl && (e.key === 'Backspace' || e.key === 'Delete')) { stop(); const tr = e.target.closest('tr[data-row-idx]'); if (tr) this.remove(parseInt(tr.dataset.rowIdx, 10)); return; }
                if (e.key === 'ArrowDown')                { stop(); this.moveVertical(e.target, 1); return; }
                if (e.key === 'ArrowUp')                  { stop(); this.moveVertical(e.target, -1); return; }
                if (e.key === 'ArrowRight' && this.atTextBoundary(e.target, 1))  { stop(); this.moveHorizontal(e.target, 1); return; }
                if (e.key === 'ArrowLeft'  && this.atTextBoundary(e.target, -1)) { stop(); this.moveHorizontal(e.target, -1); return; }
            }

            if (e.key === 'Enter' && !ctrl && tag === 'INPUT' && !inItems) {
                stop();
                this._advanceFormField(e.target);
                return;
            }
        },
    }));

    // ─── Exit-Confirm Modal Button Navigation ───────────────────────
    window.Alpine.data('fontSizePreview', (initial = 18) => ({
        size: initial,
        saved: initial,

        init() {
            this.$watch('size', (v) => {
                document.documentElement.style.setProperty('--app-font-size', v + 'px');
            });
            window.addEventListener('font-size-saved', (e) => {
                this.saved = e.detail?.size ?? this.size;
            });
        },

        destroy() {
            document.documentElement.style.setProperty('--app-font-size', this.saved + 'px');
        },
    }));

    window.Alpine.data('dnFinishNav', () => ({
        step: 1,
        chosen: null,
        selected: 0,

        init() {
            document.addEventListener('modal-show', (e) => {
                if (e.detail?.name !== 'dn-finish') return;
                this.step = 1;
                this.chosen = null;
                this.selected = 0;
                setTimeout(() => this.focusSelected(), 60);
            });
        },

        _buttons() {
            const ref = this.step === 1 ? this.$refs.step1 : this.$refs.step2;
            return ref ? Array.from(ref.querySelectorAll('button')) : [];
        },

        focusSelected() {
            this._buttons()[this.selected]?.focus();
        },

        move(dir) {
            const btns = this._buttons();
            if (!btns.length) return;
            this.selected = (this.selected + dir + btns.length) % btns.length;
            this.focusSelected();
        },

        choose(action) {
            this.chosen = action;
            this.step = 2;
            this.selected = 0;
            this.$nextTick(() => setTimeout(() => this.focusSelected(), 30));
        },

        reject() {
            window.Flux.modal('dn-finish').close();
            this.$dispatch('dn-finalize', { action: 'reject', showValues: false });
        },

        finish(showValues) {
            window.Flux.modal('dn-finish').close();
            this.$dispatch('dn-finalize', { action: this.chosen, showValues });
        },

        handleShortcut(e) {
            if (this.step !== 1) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            const map = { p: 'print', e: 'email', a: 'emailprint', h: 'holdonly' };
            const k = e.key.toLowerCase();
            if (k === 'r') { e.preventDefault(); this.reject(); return; }
            if (map[k]) { e.preventDefault(); this.choose(map[k]); }
        },
    }));

    // Keyboard-driven "commit debit note" modal: S / P / C letter shortcuts,
    // up/down/tab to move focus between the choices, Enter to activate.
    window.Alpine.data('debitNoteCommitNav', () => ({
        selected: 0,

        init() {
            document.addEventListener('modal-show', (e) => {
                if (e.detail?.name !== 'confirm-commit-debit-note') return;
                this.selected = 0;
                setTimeout(() => this.focusSelected(), 60);
            });
        },

        _buttons() {
            return this.$refs.choices ? Array.from(this.$refs.choices.querySelectorAll('button')) : [];
        },

        focusSelected() {
            this._buttons()[this.selected]?.focus();
        },

        move(dir) {
            const btns = this._buttons();
            if (!btns.length) return;
            this.selected = (this.selected + dir + btns.length) % btns.length;
            this.focusSelected();
        },

        handleShortcut(e) {
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            const map = { s: 0, p: 1, c: 2 };
            const idx = map[e.key.toLowerCase()];
            if (idx === undefined) return;
            e.preventDefault();
            this._buttons()[idx]?.click();
        },
    }));

    window.Alpine.data('exitConfirmNav', (modalName = 'exit-confirm') => ({
        selected: 0,

        init() {
            document.addEventListener('modal-show', (e) => {
                if (e.detail?.name !== modalName) return;
                this.selected = 0;
                setTimeout(() => this.focusSelected(), 60);
            });
        },

        _buttons() {
            return this.$refs.buttons?.querySelectorAll('button') ?? [];
        },

        focusSelected() {
            this._buttons()[this.selected]?.focus();
        },

        move(dir) {
            const btns = this._buttons();
            if (!btns.length) return;
            this.selected = (this.selected + dir + btns.length) % btns.length;
            this.focusSelected();
        },
    }));

    // ─── Capture-phase Enter handler for Flux switches ──────────────
    // Flux's <ui-switch> stops keydown propagation on Enter (uses it to toggle),
    // so a regular form-level handler never sees it. Install a capture listener
    // that runs before Flux and advances focus instead.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        const t = e.target;
        if (!t.hasAttribute?.('data-flux-switch')) return;
        if (!t.closest('form')) return;
        e.preventDefault();
        e.stopPropagation();
        window.focusNextFormField?.(t);
    }, true);

    // ─── Global Enter-to-dismiss for Flux toasts ─────────────────────
    // Flux only closes a toast on Enter when its close button has focus.
    // showToast() appends the rendered dialog as a direct child of
    // <ui-toast-group> (or <ui-toast> when used standalone), not nested
    // under <ui-toast>, so the selector must not assume that ancestor.
    // Capture-phase so it fires before any target-specific handler.
    // While a toast is open, this Enter press is consumed entirely (both
    // preventDefault and stopPropagation) so it can't also reach whatever
    // element happens to hold focus underneath — e.g. a same-page form
    // left focused after a non-redirecting submit, which would otherwise
    // advance to its next field or re-trigger a focused button.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        const dialogs = document.querySelectorAll('[data-flux-toast-dialog]');
        if (!dialogs.length) return;
        e.preventDefault();
        e.stopPropagation();
        dialogs.forEach((dialog) => {
            dialog.querySelector('ui-close button')?.click();
        });
    }, true);

    // ─── Focus next form field after `from` element ─────────────────
    // Used by the typeahead to advance focus after a selection.
    window.focusNextFormField = (from) => {
        if (!from) return false;
        const form = from.closest('form') ?? from.closest('[data-form-root]');
        if (!form) return false;
        const selector = 'input:not([type=hidden]):not([disabled]):not([type=submit]):not([type=button]), select:not([disabled]), textarea:not([disabled]), [data-form-stop]:not([disabled]), [data-flux-switch]:not([disabled])';
        const all = Array.from(form.querySelectorAll(selector))
            .filter((el) => el.offsetParent !== null);
        const next = all.find((el) =>
            (from.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)
            && !from.contains(el)
        );
        if (!next) return false;
        next.focus();
        if (typeof next.select === 'function' && next.type !== 'number' && next.type !== 'date') next.select();
        if (next.tagName === 'SELECT' && typeof next.showPicker === 'function') {
            try { next.showPicker(); } catch (_) {}
        }
        return true;
    };

    // ─── Generic Form Enter-Navigation ──────────────────────────────
    // Enter advances focus to the next focusable input; Ctrl+Enter submits.
    // Apply via `x-data="formNav" x-on:keydown="handleKey($event)"` on a <form>.
    window.Alpine.data('formNav', () => ({
        _selector: 'input:not([type=hidden]):not([disabled]):not([readonly]):not([type=submit]):not([type=button]), select:not([disabled]), textarea:not([disabled]):not([readonly]), [data-form-stop]:not([disabled]), [data-flux-switch]:not([disabled])',

        init() {
            this.$el.addEventListener('change', (e) => {
                if (e.target.tagName !== 'SELECT') return;
                // Selects whose change server-side toggles other fields' readonly/disabled
                // state (data-form-defer-advance) must wait for that morph to land before
                // recomputing "next" — advancing on the pre-morph DOM can focus a field
                // that's about to become readonly.
                if (e.target.hasAttribute('data-form-defer-advance')) return;
                this._advanceFocus(e.target);
            });
        },

        _advanceFocus(el) {
            const all = Array.from(this.$el.querySelectorAll(this._selector))
                .filter((el) => el.offsetParent !== null);
            const i = all.indexOf(el);
            if (i < 0) return;
            const next = all[i + 1];
            if (!next) return;
            next.focus();
            if (typeof next.select === 'function' && next.type !== 'number' && next.type !== 'date') next.select();
            if (next.tagName === 'SELECT' && typeof next.showPicker === 'function') {
                try { next.showPicker(); } catch (_) {}
            }
        },

        handleKey(e) {
            if (e.key !== 'Enter') return;
            const tag = e.target.tagName;
            const ctrl = e.ctrlKey || e.metaKey;
            const isFormInput = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA'
                || e.target.hasAttribute?.('data-form-stop')
                || e.target.hasAttribute?.('data-flux-switch');
            if (!isFormInput && tag !== 'BUTTON') return;

            if (ctrl) {
                e.preventDefault();
                const submitBtn = this.$el.querySelector('[data-form-submit]');
                if (submitBtn) { submitBtn.click(); return; }
                this.$el.requestSubmit?.() || this.$el.querySelector('button[type="submit"]')?.click();
                return;
            }

            if (tag === 'TEXTAREA') return;
            if (tag === 'BUTTON' && !e.target.hasAttribute('data-flux-switch') && !e.target.hasAttribute('data-form-nav')) return;

            e.preventDefault();
            if (tag === 'SELECT' && e.target.hasAttribute('data-form-defer-advance')) return;
            this._advanceFocus(e.target);
        },
    }));

    // ─── Role Permission Matrix Component ──────────────────────────
    // Drives a permission checkbox grid (group → module → permission key).
    // Toggles mutate a local Set only; one $wire sync + save on submit.
    // Apply via `x-data="roleMatrix(@js($config))" x-on:submit.prevent="submit()"`.
    window.Alpine.data('roleMatrix', (config = {}) => ({
        catalog: config.catalog || [],
        locked: !!config.locked,
        selectedSet: new Set(),

        init() {
            this.selectedSet = new Set(config.selected || []);
        },

        isChecked(key) {
            return this.selectedSet.has(key);
        },

        toggle(key) {
            if (this.locked) return;
            if (this.selectedSet.has(key)) {
                this.selectedSet.delete(key);
            } else {
                this.selectedSet.add(key);
            }
            this.selectedSet = new Set(this.selectedSet);
        },

        _stateOf(keys) {
            const inSet = keys.filter((k) => this.selectedSet.has(k)).length;
            if (inSet === 0) return 'none';
            if (inSet === keys.length) return 'all';
            return 'some';
        },

        _setKeys(keys, add) {
            keys.forEach((k) => {
                if (add) {
                    this.selectedSet.add(k);
                } else {
                    this.selectedSet.delete(k);
                }
            });
            this.selectedSet = new Set(this.selectedSet);
        },

        moduleState(mod) {
            return this._stateOf(mod.keys || []);
        },

        toggleModule(mod) {
            if (this.locked) return;
            const keys = mod.keys || [];
            this._setKeys(keys, this._stateOf(keys) !== 'all');
        },

        _groupKeys(group) {
            return (group.modules || []).flatMap((mod) => mod.keys || []);
        },

        groupState(group) {
            return this._stateOf(this._groupKeys(group));
        },

        toggleGroup(group) {
            if (this.locked) return;
            const keys = this._groupKeys(group);
            this._setKeys(keys, this._stateOf(keys) !== 'all');
        },

        count() {
            return this.selectedSet.size;
        },

        submit() {
            this.$wire.set('permissions', Array.from(this.selectedSet), false);
            this.$wire.save();
        },
    }));

    // ─── Per-Page Select Component ──────────────────────────────────
    window.Alpine.data('perPageSelect', () => ({
        onChange(value) {
            document.cookie = `crm_per_page=${value};path=/;max-age=31536000;SameSite=Lax`;
        },
    }));

    // ─── Payment Allocator Component ────────────────────────────────
    // Invoice rows are lazy-loaded from the server (oldest-outstanding first,
    // capped at `loadedLimit` server-side) rather than all fetched/rendered
    // at once — a customer with tens of thousands of invoices would otherwise
    // hang the tab building reactive proxies + DOM nodes for every row.
    window.Alpine.data('paymentAllocator', ({ rows, hasMore }) => ({
        rows: rows.map(r => ({ ...r, amount: r.existing_allocation })),
        hasMore: !!hasMore,
        loadingMore: false,
        _observer: null,
        // Auto-loads the next chunk as the user scrolls near the bottom of
        // the table, instead of a "Load more" click — never touches rows
        // already on screen, so in-progress typing/tabbing is undisturbed.
        init() {
            this.$nextTick(() => {
                if (!this.$refs.loadSentinel) return;
                this._observer = new IntersectionObserver((entries) => {
                    if (entries[0].isIntersecting && this.hasMore && !this.loadingMore) {
                        this.loadMore();
                    }
                }, { rootMargin: '600px' });
                this._observer.observe(this.$refs.loadSentinel);
            });
        },
        destroy() {
            this._observer?.disconnect();
        },
        get paymentAmount() { return parseFloat(this.$wire.amount) || 0; },
        get totalAllocated() {
            return this.rows.reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
        },
        get budgetRemaining() {
            return this.paymentAmount - this.totalAllocated;
        },
        get unallocatedBalance() {
            return Math.max(0, this.paymentAmount - this.totalAllocated);
        },
        get isModified() {
            return this.rows.some(r => (parseFloat(r.amount) || 0) !== (parseFloat(r.existing_allocation) || 0));
        },
        // Only rows with a nonzero amount or a prior allocation matter to the
        // save — a customer with thousands of invoice rows would otherwise
        // ship the whole table over the wire on every save.
        get relevantRows() {
            return this.rows.filter(r => (parseFloat(r.amount) || 0) > 0 || (parseFloat(r.existing_allocation) || 0) > 0);
        },
        resetAllocations() {
            this.rows.forEach(r => { r.amount = 0; });
        },
        // Merges newly-loaded rows (from "load more" or an invoice search) into
        // the existing table without touching rows already present, so amounts
        // the user already typed are never disturbed.
        appendRows(newRows, hasMore) {
            const existingIds = new Set(this.rows.map(r => r.id));
            newRows.forEach(nr => {
                if (!existingIds.has(nr.id)) {
                    this.rows.push({ ...nr, amount: nr.existing_allocation });
                }
            });
            this.hasMore = !!hasMore;
        },
        // Auto Allocate runs server-side (it needs the full outstanding set,
        // not just what's loaded) and returns the resulting amounts; any
        // allocated invoice outside the currently loaded window is merged in,
        // then every row's amount is set from the result (zero if untouched).
        applyAutoAllocation(newRows, allocations, hasMore) {
            const existingIds = new Set(this.rows.map(r => r.id));
            newRows.forEach(nr => {
                if (!existingIds.has(nr.id)) {
                    this.rows.push({ ...nr, amount: nr.existing_allocation });
                }
            });
            this.rows.forEach(r => {
                r.amount = allocations[r.id] !== undefined ? allocations[r.id] : 0;
            });
            this.hasMore = !!hasMore;
        },
        loadMore() {
            if (this.loadingMore) return;
            this.loadingMore = true;
            this.$wire.loadMoreInvoices().finally(() => { this.loadingMore = false; });
        },
        // Previews a suggested amount when an empty row gains focus. The preview
        // is not committed: @blur reverts it unless the user pressed Enter,
        // which (via handleKey) is the only action that persists it.
        _previewRowId: null,
        _previewPrev: null,
        focusRow(row) {
            if (parseFloat(row.amount)) return;
            const budgetExcludingRow = this.paymentAmount - this.rows.reduce(
                (sum, r) => sum + (r === row ? 0 : (parseFloat(r.amount) || 0)), 0
            );
            const suggested = Math.min(Math.max(budgetExcludingRow, 0), parseFloat(row.max_allocatable) || 0);
            if (suggested > 0) {
                this._previewRowId = row.id;
                this._previewPrev = row.amount;
                row.amount = Math.round(suggested * 100) / 100;
            }
        },
        commitPreview() {
            this._previewRowId = null;
            this._previewPrev = null;
        },
        revertPreviewIfPending(row) {
            if (this._previewRowId !== row.id) return false;
            row.amount = this._previewPrev;
            this._previewRowId = null;
            this._previewPrev = null;
            return true;
        },
        _allocInputs() {
            return Array.from(this.$el.querySelectorAll('[data-payment-alloc-input]'))
                .filter(f => f.offsetParent !== null);
        },
        handleKey(e) {
            if (!e.target.hasAttribute('data-payment-alloc-input')) return;
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Enter') return;
            e.preventDefault();
            // formNav's own Enter handler skips select() on type=number inputs
            // globally (elsewhere intentional); stop it reaching this input so
            // allocation rows always highlight their value on Enter too.
            e.stopPropagation();
            // Enter is the only action that commits a focus preview; Arrow
            // navigation leaves it for the pending blur to revert.
            if (e.key === 'Enter') { this.commitPreview(); }
            const inputs = this._allocInputs();
            const idx = inputs.indexOf(e.target);
            const next = inputs[idx + (e.key === 'ArrowUp' ? -1 : 1)];
            // .focus() alone is enough — the input's own @focus handler runs
            // focusRow() + a nextTick-deferred select(), so calling select()
            // here too would race Alpine's reactive value write and lose.
            if (next) next.focus();
        },
    }));

    window.Alpine.data('supplierDebitNoteItems', (initialItems, vatRate = 20) => ({
        rows: initialItems.length ? initialItems : [{ description: '', quantity: '', amount: '', vat_applicable: true, total: 0 }],
        vatRate,
        _rowCells: 'input, select',

        addRow() {
            this.rows.push({ description: '', quantity: '', amount: '', vat_applicable: true, total: 0 });
            this.$nextTick(() => {
                const inputs = this.$el.querySelectorAll('[data-dn-row-desc]');
                const last = inputs[inputs.length - 1];
                if (last) { last.focus(); last.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
            });
        },

        removeRow(i) {
            if (this.rows.length > 1) this.rows.splice(i, 1);
        },

        rowTotal(r) {
            return (parseFloat(r.quantity) || 0) * (parseFloat(r.amount) || 0);
        },

        get subtotal() {
            return this.rows.reduce((s, r) => s + this.rowTotal(r), 0);
        },

        get hasVat() {
            return this.rows.some(r => r.vat_applicable);
        },

        get vatTotal() {
            return Math.round(
                this.rows
                    .filter(r => r.vat_applicable)
                    .reduce((s, r) => s + this.rowTotal(r) * this.vatRate / 100, 0)
                * 100) / 100;
        },

        get grossTotal() {
            return Math.round((this.subtotal + this.vatTotal) * 100) / 100;
        },

        _focusField(el) {
            if (!el) return;
            el.focus();
            if (el.type !== 'number' && typeof el.select === 'function') el.select();
            if (el.tagName === 'SELECT' && typeof el.showPicker === 'function') {
                try { el.showPicker(); } catch (_) {}
            }
        },

        atTextBoundary(input, dir) {
            if (input.type === 'number') return true;
            if (typeof input.value !== 'string') return true;
            try {
                const { selectionStart: s, selectionEnd: e } = input;
                if (s === null) return true;
                return dir > 0 ? s === input.value.length && e === input.value.length : s === 0 && e === 0;
            } catch (_) { return true; }
        },

        moveVertical(target, dir) {
            const tr = target.closest('tr[data-row-idx]');
            if (!tr) return;
            const rowIdx = parseInt(tr.dataset.rowIdx, 10);
            const cells = Array.from(tr.querySelectorAll(this._rowCells));
            const colIdx = cells.indexOf(target);
            const tbody = this.$el.querySelector('tbody');
            const targetTr = tbody?.querySelector(`tr[data-row-idx="${rowIdx + dir}"]`);
            if (!targetTr) return;
            const targetCells = Array.from(targetTr.querySelectorAll(this._rowCells));
            this._focusField(targetCells[colIdx] ?? targetCells[0]);
        },

        moveHorizontal(target, dir) {
            const tbody = this.$el.querySelector('tbody');
            const cells = Array.from(tbody?.querySelectorAll(this._rowCells) ?? []);
            const idx = cells.indexOf(target);
            if (idx < 0) return;
            const next = cells[idx + dir];
            if (next) this._focusField(next);
        },

        advanceFromItem(target) {
            const tr = target.closest('tr[data-row-idx]');
            if (!tr) return;
            const rowIdx = parseInt(tr.dataset.rowIdx, 10);
            const cells = Array.from(tr.querySelectorAll(this._rowCells));
            const colIdx = cells.indexOf(target);
            const nextSibling = cells[colIdx + 1];
            if (nextSibling) { this._focusField(nextSibling); return; }
            const tbody = this.$el.querySelector('tbody');
            const nextTr = tbody?.querySelector(`tr[data-row-idx="${rowIdx + 1}"]`);
            if (nextTr) {
                const first = nextTr.querySelector(this._rowCells);
                if (first) { this._focusField(first); return; }
            }
            this.addRow();
        },

        handleKey(e) {
            if (!e.target.closest('[data-dn-items-table]')) return;
            const stop = () => { e.preventDefault(); e.stopPropagation(); };
            if (e.key === 'Enter') { stop(); this.advanceFromItem(e.target); return; }
            if (e.key === 'ArrowDown') { stop(); this.moveVertical(e.target, 1); return; }
            if (e.key === 'ArrowUp') { stop(); this.moveVertical(e.target, -1); return; }
            if (e.key === 'ArrowRight' && this.atTextBoundary(e.target, 1)) { stop(); this.moveHorizontal(e.target, 1); return; }
            if (e.key === 'ArrowLeft' && this.atTextBoundary(e.target, -1)) { stop(); this.moveHorizontal(e.target, -1); return; }
        },

        preCommit() {
            this.$wire.set('items', this.rows.map(r => ({ ...r, total: this.rowTotal(r) })), false)
                .then(() => this.$flux.modal('confirm-commit-debit-note').show());
        },
    }));

    window.Alpine.data('supplierDebitNoteForm', () => ({
        init() {
            document.addEventListener('modal-show', (e) => {
                if (e.detail?.name !== 'confirm-commit-debit-note') return;
                setTimeout(() => document.querySelector('[data-dn-commit-btn]')?.focus(), 60);
            });
            window.addEventListener('payout-focus-dn', () => {
                this.$nextTick(() => {
                    const el = this.$el.querySelector('[data-form-stop]') ?? this.$el.querySelector('input:not([type=hidden])');
                    if (el) { el.focus(); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                });
            });
        },

        _formInputs() {
            return Array.from(this.$el.querySelectorAll(
                'input:not([type=hidden]):not([type=file]):not([disabled]), select:not([disabled])'
            )).filter(f => f.offsetParent !== null && !f.closest('[data-dn-items-table]'));
        },

        _focusField(el) {
            if (!el) return;
            el.focus();
            if (el.type !== 'number' && typeof el.select === 'function') el.select();
        },

        handleKey(e) {
            if (!this.$el.contains(e.target)) return;
            if (e.target.closest('[data-dn-items-table]')) return;
            const tag = e.target.tagName;
            const stop = () => { e.preventDefault(); e.stopPropagation(); };

            if (e.key === 'Enter' && tag !== 'TEXTAREA' && (tag === 'INPUT' || tag === 'SELECT')) {
                stop();
                const inputs = this._formInputs();
                const idx = inputs.indexOf(e.target);
                const next = inputs[idx + 1];
                if (next) {
                    this._focusField(next);
                } else {
                    window.dispatchEvent(new CustomEvent('dn-focus-first-item'));
                }
            }
        },
    }));

    window.Alpine.data('supplierPayoutAllocator', ({ rows, focusAmount = false }) => ({
        rows: rows.map(r => ({ ...r, allocated_amount: r.allocated_amount ?? 0 })),
        _baseline: {},

        _rowKey(r) {
            return r.id ?? ('dn-' + r.debit_note_id);
        },

        // Snapshot the current allocations as the "unmodified" state — Reset
        // restores to this, and it drives the Reset button's visibility.
        rebaseline() {
            this._baseline = {};
            this.rows.forEach(r => { this._baseline[this._rowKey(r)] = parseFloat(r.allocated_amount) || 0; });
        },

        init() {
            this.rebaseline();

            document.addEventListener('modal-show', (e) => {
                if (e.detail?.name !== 'confirm-allocation') return;
                setTimeout(() => document.querySelector('[data-payout-confirm-btn]')?.focus(), 60);
            });
            window.addEventListener('dn-focus-payout', () => {
                this.$nextTick(() => {
                    const el = this.$el.querySelector('[data-form-stop]') ?? this.$el.querySelector('input');
                    if (el) { el.focus(); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                });
            });

            // Arriving from "Save & move to payout": supplier + debit notes are
            // already filled, so the amount is the field awaiting input.
            if (focusAmount) {
                this.$nextTick(() => setTimeout(() => {
                    const el = this.$el.querySelector('[data-payout-amount]');
                    if (el) { el.focus(); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                }, 80));
            }
        },

        get payoutAmount() {
            // Amount uses deferred wire:model (persists on Enter), so $wire.amount
            // lags what the user just typed — read the live input value instead.
            const el = this.$refs.payoutAmountInput
                ?? this.$el?.querySelector?.('[data-payout-amount]')
                ?? document.querySelector('[data-payout-amount]');
            const raw = el && el.value !== '' ? el.value : this.$wire.amount;
            return parseFloat(raw) || 0;
        },

        get totalAllocated() {
            return this.rows.reduce((s, r) => s + (parseFloat(r.allocated_amount) || 0), 0);
        },

        get unallocated() {
            return Math.max(0, this.payoutAmount - this.totalAllocated);
        },

        get isModified() {
            return this.rows.some(r => (parseFloat(r.allocated_amount) || 0) !== (this._baseline[this._rowKey(r)] ?? 0));
        },

        autoAllocate(amount) {
            let remaining = (amount !== undefined && !isNaN(amount)) ? parseFloat(amount) : this.payoutAmount;
            this.rows.forEach(r => {
                const effective = parseFloat(r.effective_outstanding) || 0;
                if (effective <= 0 || remaining <= 0) {
                    r.allocated_amount = 0;
                    return;
                }
                const alloc = Math.min(effective, remaining);
                r.allocated_amount = Math.round(alloc * 100) / 100;
                remaining = Math.round((remaining - alloc) * 100) / 100;
            });
        },

        resetAllocations() {
            this.rows.forEach(r => { r.allocated_amount = this._baseline[this._rowKey(r)] ?? 0; });
        },

        // Previews a suggested amount when an empty row gains focus. The preview
        // is not committed: @blur reverts it unless the user pressed Enter,
        // which (via handleKey) is the only action that persists it.
        _previewRowKey: null,
        _previewPrev: null,

        focusRow(row) {
            if (parseFloat(row.allocated_amount)) return;
            const budgetExcludingRow = this.payoutAmount - this.rows.reduce(
                (sum, r) => sum + (r === row ? 0 : (parseFloat(r.allocated_amount) || 0)), 0
            );
            const suggested = Math.min(Math.max(budgetExcludingRow, 0), parseFloat(row.effective_outstanding) || 0);
            if (suggested > 0) {
                this._previewRowKey = this._rowKey(row);
                this._previewPrev = row.allocated_amount;
                row.allocated_amount = Math.round(suggested * 100) / 100;
            }
        },

        commitPreview() {
            this._previewRowKey = null;
            this._previewPrev = null;
        },

        revertPreviewIfPending(row) {
            if (this._previewRowKey !== this._rowKey(row)) return false;
            row.allocated_amount = this._previewPrev;
            this._previewRowKey = null;
            this._previewPrev = null;
            return true;
        },

        _allocInputs() {
            return Array.from(this.$el.querySelectorAll('[data-payout-alloc-input]'))
                .filter(f => f.offsetParent !== null);
        },

        _formInputs() {
            return Array.from(this.$el.querySelectorAll(
                'input:not([type=hidden]):not([type=file]):not([disabled]):not([data-payout-alloc-input]), select:not([disabled])'
            )).filter(f => f.offsetParent !== null);
        },

        _focusField(el) {
            if (!el) return;
            el.focus();
            if (el.type !== 'number' && typeof el.select === 'function') el.select();
        },

        handleKey(e) {
            if (!this.$el.contains(e.target)) return;
            const tag = e.target.tagName;
            const isAllocInput = e.target.hasAttribute('data-payout-alloc-input');
            const stop = () => { e.preventDefault(); e.stopPropagation(); };

            if (e.key === 'Enter' && tag !== 'TEXTAREA') {
                stop();
                if (isAllocInput) {
                    this.commitPreview();
                    const inputs = this._allocInputs();
                    const idx = inputs.indexOf(e.target);
                    const next = inputs[idx + 1];
                    if (next) this._focusField(next);
                } else if (tag === 'INPUT' || tag === 'SELECT') {
                    const inputs = this._formInputs();
                    const idx = inputs.indexOf(e.target);
                    const next = inputs[idx + 1];
                    if (next) { this._focusField(next); } else { this._focusField(this._allocInputs()[0]); }
                }
            }
        },
    }));

    // ─── Show-Page Context Keys Component ───────────────────────────
    window.Alpine.data('showPageKeys', (config = {}) => ({
        init() {
            Alpine.store('hotkeys').registerContext(config);
        },

        destroy() {
            Alpine.store('hotkeys').clearContext();
        },
    }));
});
