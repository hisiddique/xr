document.addEventListener('alpine:init', () => {
    // ─── Global Hotkeys Store ───────────────────────────────────────
    window.Alpine.store('hotkeys', {
        showLabels: false,
        selectedRow: -1,
        rowCount: 0,
        pendingModal: null,   // modal name awaiting y/n confirmation
        contextActions: {},   // F7/F8/F9 overrides from show pages
        tableActions: null,   // callbacks set by tableNav component
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

            // ── Ignore typing in form fields ──
            if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable) {
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

            // ── Table navigation keys (only when tableNav is active) ──
            if (this.tableActions) {
                if (lowerKey === 'j' || key === 'ArrowDown') {
                    e.preventDefault();
                    this.moveSelection(1);
                    return;
                }
                if (lowerKey === 'k' || key === 'ArrowUp') {
                    e.preventDefault();
                    this.moveSelection(-1);
                    return;
                }
                if (key === 'Escape') {
                    e.preventDefault();
                    this.selectedRow = -1;
                    return;
                }

                // Row action keys (only when a row is selected)
                if (this.selectedRow >= 0) {
                    if (key === 'Enter' || lowerKey === 'o') {
                        e.preventDefault();
                        this.tableActions.view(this.selectedRow);
                        return;
                    }
                    if (lowerKey === 'e') {
                        e.preventDefault();
                        this.tableActions.edit(this.selectedRow);
                        return;
                    }
                    if (lowerKey === 'm') {
                        e.preventDefault();
                        this.tableActions.email(this.selectedRow);
                        return;
                    }
                    if (lowerKey === 'c') {
                        e.preventDefault();
                        this.tableActions.convert(this.selectedRow);
                        return;
                    }
                    if (lowerKey === 'd') {
                        e.preventDefault();
                        this.tableActions.delete(this.selectedRow);
                        return;
                    }
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
        },

        scrollSelectedIntoView() {
            const idx = this.selectedRow;
            requestAnimationFrame(() => {
                const row = document.querySelector(`tr[data-row-index="${idx}"]`);
                if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
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
        },

        clearTable() {
            this.rowCount = 0;
            this.tableActions = null;
            this.selectedRow = -1;
        },

        openModalWithConfirm(modalName) {
            if (window.Flux) {
                Flux.modal(modalName).show();
                this.pendingModal = modalName;
            }
        },
    });

    // ─── Table Navigation Component ─────────────────────────────────
    window.Alpine.data('tableNav', () => ({
        rows: [],

        init() {
            this.scanRows();
            Alpine.store('hotkeys').registerTable(this.rows.length, {
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
                store.rowCount = this.rows.length;
                if (store.selectedRow >= this.rows.length) {
                    store.selectedRow = -1;
                }
            });
            const tbody = this.$el.querySelector('tbody');
            if (tbody) {
                this._observer.observe(tbody, { childList: true, subtree: true });
            }
        },

        destroy() {
            Alpine.store('hotkeys').clearTable();
            if (this._observer) {
                this._observer.disconnect();
            }
        },

        scanRows() {
            const trs = this.$el.querySelectorAll('tr[data-row-index]');
            this.rows = Array.from(trs).map((tr) => ({
                viewUrl: tr.dataset.viewUrl || null,
                editUrl: tr.dataset.editUrl || null,
                emailModal: tr.dataset.emailModal || null,
                convertModal: tr.dataset.convertModal || null,
                deleteModal: tr.dataset.deleteModal || null,
            }));
        },

        viewRow(i) {
            const row = this.rows[i];
            if (row?.viewUrl) Livewire.navigate(row.viewUrl);
        },

        editRow(i) {
            const row = this.rows[i];
            if (row?.editUrl) Livewire.navigate(row.editUrl);
        },

        emailRow(i) {
            const row = this.rows[i];
            if (row?.emailModal && window.Flux) {
                Flux.modal(row.emailModal).show();
            }
        },

        convertRow(i) {
            const row = this.rows[i];
            if (row?.convertModal) {
                Alpine.store('hotkeys').openModalWithConfirm(row.convertModal);
            }
        },

        deleteRow(i) {
            const row = this.rows[i];
            if (row?.deleteModal) {
                Alpine.store('hotkeys').openModalWithConfirm(row.deleteModal);
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
