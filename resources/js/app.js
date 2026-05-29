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
                window.history.back();
                return;
            }

            // ── '?' opens help modal ──
            if (key === '?') {
                e.preventDefault();
                if (window.Flux) Flux.modal('hotkeys-help').show();
                return;
            }

            // ── '+' creates new on scoped list pages ──
            if (key === '+') {
                const path = window.location.pathname;
                const map = {
                    '/customers': '/customers/create',
                    '/delivery-notes': '/delivery-notes/create',
                    '/users': '/users/create',
                };
                if (map[path]) {
                    e.preventDefault();
                    Livewire.navigate(map[path]);
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
                    const form = Array.from(document.querySelectorAll('form'))
                        .find((f) => f.offsetParent !== null);
                    if (form) {
                        if (typeof form.requestSubmit === 'function') form.requestSubmit();
                        else form.querySelector('button[type="submit"]')?.click();
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
                case 9:
                    if (this.contextActions.f9) this.contextActions.f9();
                    break;
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
            if (row?.emailModal && window.Flux) {
                Flux.modal(row.emailModal).show();
            }
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

    // ─── Line-Item Form Component (DN/Invoice create/edit) ─────────
    window.Alpine.data('lineItemForm', (initialRows = [], units = [], fallback = '/', defaults = {}) => ({
        rows: initialRows,
        units,
        fallback,
        _lineDefault: { details: '', quantity: '', price: '', per: '', is_note: false, ...(defaults.line || {}) },
        _noteDefault: { details: '', quantity: '0', price: '', per: '', is_note: true, ...(defaults.note || {}) },

        init() {
            this.$nextTick(() => {
                this.$el.querySelector('input:not([type=hidden]):not([type=file]), textarea, select')?.focus();
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

        _rowCells: 'input, button[data-row-remove]:not([hidden]):not([style*="display: none"])',

        moveVertical(target, dir) {
            const cell = target.closest('input, button[data-row-remove]') ?? target;
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
            if (next) {
                next.focus();
                if (typeof next.select === 'function' && next.type !== 'number') next.select();
            }
        },

        moveHorizontal(target, dir) {
            const cell = target.closest('input, button[data-row-remove]') ?? target;
            const cells = Array.from(this.$refs.rowsBody?.querySelectorAll(this._rowCells) ?? []);
            const idx = cells.indexOf(cell);
            if (idx < 0) return;
            const next = cells[idx + dir];
            if (next) {
                next.focus();
                if (typeof next.select === 'function' && next.type !== 'number') next.select();
            }
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
            const n = Number(per);
            if (per !== '' && Number.isFinite(n) && n > 0) {
                return (q / n) * p;
            }
            return q * p;
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
                if (e.key === 'Enter' && e.shiftKey)                 { stop(); this.addNote(); return; }
                if (e.key === 'Enter' && !ctrl)                      { stop(); this.advanceFromItem(e.target); return; }
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

            // Accept unit ghost on the Per cell before advancing.
            if (cell.tagName === 'INPUT' && cell.getAttribute('list') === 'units-options') {
                const match = this.unitGhostMatch(cell.value);
                if (match) {
                    cell.value = match;
                    cell.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }

            const cells = Array.from(tr.querySelectorAll(this._rowCells));
            const colIdx = cells.indexOf(cell);
            const nextSibling = cells[colIdx + 1];
            if (nextSibling) {
                nextSibling.focus();
                if (typeof nextSibling.select === 'function' && nextSibling.type !== 'number') nextSibling.select();
                return;
            }
            const nextTr = this.$refs.rowsBody?.querySelector(`tr[data-row-idx="${rowIdx + 1}"]`);
            if (nextTr) {
                const first = nextTr.querySelector(this._rowCells);
                if (first) {
                    first.focus();
                    if (typeof first.select === 'function' && first.type !== 'number') first.select();
                }
                return;
            }
            this.add();
        },

        _formFieldSelector: 'input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [data-flux-switch], [data-form-stop], button[data-form-nav]:not([disabled])',

        _focusField(el) {
            if (!el) return;
            el.focus();
            if (typeof el.select === 'function' && el.type !== 'number' && el.type !== 'date') el.select();
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
    }));

    // ─── Exit-Confirm Modal Button Navigation ───────────────────────
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

    // ─── Focus next form field after `from` element ─────────────────
    // Used by the typeahead to advance focus after a selection.
    window.focusNextFormField = (from) => {
        if (!from) return false;
        const form = from.closest('form');
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
        return true;
    };

    // ─── Generic Form Enter-Navigation ──────────────────────────────
    // Enter advances focus to the next focusable input; Ctrl+Enter submits.
    // Apply via `x-data="formNav" x-on:keydown="handleKey($event)"` on a <form>.
    window.Alpine.data('formNav', () => ({
        _selector: 'input:not([type=hidden]):not([disabled]):not([type=submit]):not([type=button]), select:not([disabled]), textarea:not([disabled]), [data-form-stop]:not([disabled]), [data-flux-switch]:not([disabled])',

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
                this.$el.requestSubmit?.() || this.$el.querySelector('button[type="submit"]')?.click();
                return;
            }

            if (tag === 'TEXTAREA') return;
            if (tag === 'BUTTON' && !e.target.hasAttribute('data-flux-switch') && !e.target.hasAttribute('data-form-nav')) return;

            e.preventDefault();
            const all = Array.from(this.$el.querySelectorAll(this._selector))
                .filter((el) => el.offsetParent !== null);
            const i = all.indexOf(e.target);
            if (i < 0) return;
            const next = all[i + 1];
            if (next) {
                next.focus();
                if (typeof next.select === 'function' && next.type !== 'number' && next.type !== 'date') next.select();
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
