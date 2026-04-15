document.addEventListener('alpine:init', () => {
    window.Alpine.data('hotkeys', () => ({
        chordTimer: null,
        awaitingChord: false,

        init() {
            window.addEventListener('keydown', (e) => this.handleKeydown(e));
        },

        handleKeydown(e) {
            // Ignore if target is an input, textarea, select, or contenteditable
            const target = e.target;
            if (
                target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' ||
                target.tagName === 'SELECT' ||
                target.isContentEditable
            ) {
                return;
            }

            // Ignore if a Flux modal is open (check for modal overlay)
            if (document.querySelector('[data-flux-modal][data-open]')) {
                return;
            }

            // Ignore modifier keys
            if (e.ctrlKey || e.metaKey || e.altKey) {
                return;
            }

            const key = e.key.toLowerCase();

            // '?' opens hotkeys help modal
            if (key === '?') {
                e.preventDefault();
                this.awaitingChord = false;
                this.clearChordTimer();
                window.dispatchEvent(new CustomEvent('open-hotkeys-help'));
                return;
            }

            // 'n' — context new based on current path
            if (key === 'n') {
                e.preventDefault();
                this.awaitingChord = false;
                this.clearChordTimer();
                this.handleNew();
                return;
            }

            // 'g' — start chord
            if (key === 'g' && !this.awaitingChord) {
                e.preventDefault();
                this.awaitingChord = true;
                this.chordTimer = setTimeout(() => {
                    this.awaitingChord = false;
                }, 1000);
                return;
            }

            // Chord second key
            if (this.awaitingChord) {
                e.preventDefault();
                this.awaitingChord = false;
                this.clearChordTimer();

                if (key === 'c') {
                    Livewire.navigate('/customers');
                } else if (key === 'd') {
                    Livewire.navigate('/delivery-notes');
                } else if (key === 'i') {
                    Livewire.navigate('/invoices');
                }
            }
        },

        handleNew() {
            const path = window.location.pathname;
            if (path.startsWith('/customers')) {
                Livewire.navigate('/customers/create');
            } else if (path.startsWith('/delivery-notes')) {
                Livewire.navigate('/delivery-notes/create');
            }
        },

        clearChordTimer() {
            if (this.chordTimer) {
                clearTimeout(this.chordTimer);
                this.chordTimer = null;
            }
        },
    }));
});
