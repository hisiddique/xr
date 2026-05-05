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
                case 2: Livewire.navigate('/delivery-notes/create'); break;
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
            if (row?.convertModal) {
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
            if (Number.isInteger(idx)) this.remove(idx);
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

        handleKey(e) {
            const tag = e.target.tagName;
            const inItems = (tag === 'INPUT' || tag === 'SELECT') && e.target.closest('[data-items-table]');
            const ctrl = e.ctrlKey || e.metaKey;

            if (ctrl && e.key === 'Enter')                       { e.preventDefault(); this.submit(); return; }
            if (e.key === 'Escape')                              { e.preventDefault(); this.cancel(); return; }
            if (!inItems) return;
            if (e.key === 'Enter' && e.shiftKey)                 { e.preventDefault(); this.addNote(); return; }
            if (e.key === 'Enter')                               { e.preventDefault(); this.add(); return; }
            if (ctrl && e.key === 'Backspace')                   { e.preventDefault(); this.removeFocused(e.target); return; }
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
