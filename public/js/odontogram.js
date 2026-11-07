/*
 * Odontograma (resources/views/livewire/odontogram-chart.blade.php): la ficha de la pieza, el pincel (el hallazgo
 * elegido), el buscador que también entiende «36 MO caries», los filtros, la pantalla completa y los atajos.
 *
 * Todo eso vive en el navegador y cada toque se pinta al instante: al servidor va solo la marca. Mientras no
 * responde, cada vez que el dibujo se vuelve a armar se repinta lo pendiente; al responder, manda lo que guardó.
 * Se carga en el <head> del panel (antes que Alpine) y el navegador lo guarda en caché.
 */
(() => {
    const plain = (text) => (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    const remembered = (key, fallback) => {
        try {
            return localStorage.getItem(key) ?? fallback;
        } catch (error) {
            return fallback;
        }
    };
    const remember = (key, value) => {
        try {
            localStorage.setItem(key, value);
        } catch (error) {}
    };
    const coarse = () => window.matchMedia('(pointer: coarse)').matches;
    const typing = (el) => el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
    const ZONES = ['top', 'right', 'bottom', 'left', 'center'];

    const register = () => Alpine.data('odontogram', (config) => ({
        editable: config.editable,
        catalog: config.catalog ?? {},
        frequent: config.frequent ?? [],
        favorites: config.favorites ?? [],
        quick: config.quick ?? { anterior: [], posterior: [] },
        surfaceNames: config.surfaceNames ?? {},

        // Lo que manda el servidor en cada respuesta: filas y, por pieza, su nombre y sus marcas.
        rows: [],
        teeth: {},

        // El pincel: hallazgo, color y sigla elegidos.
        tool: null,
        state: 'bad',
        abbreviation: null,
        spanStart: null,
        states: {},
        pending: {},

        // La pieza abierta en la ficha, el filtro, la pantalla completa.
        focus: null,
        faces: {},
        filter: null,
        expanded: false,

        // El buscador: un hallazgo por nombre, o lo escrito o dictado («36 MO caries»).
        query: '',
        searching: false,
        highlighted: 0,
        sendTimer: null,
        digits: '',
        digitsTimer: null,

        planTotal: 0,
        picked: 0,
        stopListening: null,

        init() {
            this.readState();
            this.stopListening = Livewire.hook('morphed', ({ component }) => {
                if (component.id === this.$wire.$id) {
                    this.readState();
                    this.paint();
                }
            });
            this.$nextTick(() => {
                this.paint();
                this.centerSheet();
            });
        },

        destroy() {
            this.stopListening?.();
            this.lockPage(false);
        },

        readState() {
            const el = this.$root.querySelector('[data-odv-state]');

            try {
                const data = JSON.parse(el?.dataset.odvState || '{}');
                this.rows = data.rows ?? [];
                this.teeth = data.teeth ?? {};
            } catch (error) {
                this.rows = [];
                this.teeth = {};
            }

            if (this.focus !== null && ! this.teeth[this.focus]) {
                this.focus = null;
            }
        },

        // ---------- El pincel ----------

        get current() {
            return this.tool ? this.catalog[this.tool] : null;
        },

        get hint() {
            if (! this.current) {
                return '';
            }

            const where = {
                surface: coarse() ? 'Toca la pieza y marca sus caras en grande.' : 'Toca la cara de la pieza (o la pieza, para verla en grande).',
                span: this.spanStart === null ? 'Toca la primera pieza y luego la última.' : `Ahora toca la última pieza (desde la ${this.spanStart}).`,
            }[this.current.appliesTo] ?? 'Toca la pieza.';

            return `${where} Tocar de nuevo la quita.`;
        },

        meaning(option) {
            return this.current?.meanings[option]?.[1] ?? null;
        },

        word(option) {
            const word = this.current?.meanings[option]?.[0];

            return word && plain(word) !== plain(option) ? word : null;
        },

        select(code) {
            const condition = code && code !== this.tool ? this.catalog[code] : null;

            this.tool = condition ? code : null;
            // Lo que se puede tener sano o tratado (restauración, corona…) empieza en azul; lo demás, en rojo.
            this.state = ! condition ? 'bad' : (condition.good && condition.bad ? (this.states[code] ?? 'good') : (condition.good ? 'good' : 'bad'));
            this.abbreviation = condition ? this.savedAbbreviation(code) : null;
            this.spanStart = null;
            this.paint();

            // Al aparecer o irse el pincel, el dibujo se mueve: que la pieza abierta siga a la vista.
            if (this.focus !== null) {
                this.$nextTick(() => this.reveal(this.focus));
            }
        },

        setState(state) {
            if (this.current?.[state]) {
                this.state = state;
                this.states[this.tool] = state;
            }
        },

        setAbbreviation(option) {
            this.abbreviation = this.abbreviation === option ? null : option;
            remember(`odontograma.sigla.${this.tool}`, this.abbreviation ?? '');
        },

        savedAbbreviation(code) {
            const saved = remembered(`odontograma.sigla.${code}`, null);

            return this.catalog[code]?.abbreviations.includes(saved) ? saved : null;
        },

        // ---------- Tocar el dibujo ----------

        onSheetClick(event) {
            const face = event.target.closest('[data-surface]');
            const group = event.target.closest('[data-tooth-group]');

            if (! group) {
                return;
            }

            const tooth = Number(group.dataset.toothGroup);
            const surface = face?.dataset.surface ?? null;
            const appliesTo = this.current?.appliesTo;

            // Sin pincel, o sin poder cambiar: el toque abre la ficha de la pieza (o la cierra).
            if (! this.editable || ! this.current) {
                this.focus === tooth ? this.closeTooth() : this.openTooth(tooth);

                return;
            }

            // Una cara se marca directo con el mouse; con el dedo (o tocando la pieza) se abre en grande.
            if (appliesTo === 'surface') {
                if (surface && ! coarse()) {
                    this.mark(tooth, surface);
                } else {
                    this.openTooth(tooth);
                }

                return;
            }

            if (this.focus !== null) {
                this.focus = tooth;
            }

            this.mark(tooth);
        },

        mark(tooth, surface = null) {
            const condition = this.current;

            if (! condition || ! this.editable) {
                return;
            }

            if (condition.appliesTo === 'span' && this.spanStart === null) {
                this.spanStart = tooth;
                this.paint();

                return;
            }

            const onSurface = condition.appliesTo === 'surface';
            const from = condition.appliesTo === 'span' ? this.spanStart : tooth;
            const to = condition.appliesTo === 'span' ? tooth : null;
            const call = () => this.$wire.mark(from, onSurface ? surface : null, this.tool, this.state, this.abbreviation, to);
            this.spanStart = null;

            // Sin superficie no hay qué pintar: el servidor avisa qué falta.
            if (onSurface && ! surface) {
                call();

                return;
            }

            const key = onSurface ? `${tooth}-${surface}` : `${from}`;
            const shown = onSurface ? this.surfaceEl(tooth, surface)?.getAttribute('class').match(/\bis-(bad|good)\b/)?.[1] : null;
            this.pending[key] = {
                tooth: from,
                surface: onSurface ? surface : null,
                paint: shown === this.state ? 'none' : this.state,
                count: (this.pending[key]?.count ?? 0) + 1,
            };
            this.paint();

            call().finally(() => {
                if (--this.pending[key].count === 0) {
                    delete this.pending[key];
                }

                this.paint();
            });
        },

        // ---------- La ficha de la pieza ----------

        get tooth() {
            return this.focus !== null ? this.teeth[this.focus] ?? null : null;
        },

        get quickList() {
            const codes = this.tooth?.posterior ? this.quick.posterior : this.quick.anterior;

            return codes.filter((code) => this.catalog[code]);
        },

        get facesHint() {
            if (! this.editable) {
                return '';
            }

            if (this.current?.appliesTo === 'span') {
                return this.hint;
            }

            if (this.current?.appliesTo === 'surface') {
                const sigla = this.abbreviation ? ` ${this.abbreviation}` : '';

                return `${this.current.short ?? this.current.name}${sigla}, ${this.state === 'good' ? 'tratado' : 'por tratar'}: toca una cara.`;
            }

            return 'Para marcar caras, elige caries o restauración.';
        },

        openTooth(tooth) {
            if (tooth === undefined || ! this.teeth[tooth]) {
                return;
            }

            this.focus = tooth;
            this.paint();
            this.$nextTick(() => this.reveal(tooth));
        },

        // Que la pieza abierta se vea: dentro del dibujo (que en el celular se desliza) y, si la ficha sale
        // desde abajo, por encima de ella.
        reveal(tooth) {
            const group = this.$root.querySelector(`[data-tooth-group="${tooth}"]`);
            const scroller = this.$root.querySelector('.odv-scroll');

            if (! group || ! scroller) {
                return;
            }

            const box = group.getBoundingClientRect();
            const view = scroller.getBoundingClientRect();

            if (box.left < view.left || box.right > view.right) {
                scroller.scrollLeft += box.left - view.left - (view.width - box.width) / 2;
            }

            const card = this.$root.querySelector('.odv-card');
            const floating = card && getComputedStyle(card).position === 'fixed';
            const bottom = floating ? card.getBoundingClientRect().top - 8 : window.innerHeight - 8;
            const covered = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--cb-offset'));
            const top = this.expanded ? 8 : (covered || 72) + 8;

            const page = this.expanded ? this.$root.querySelector('.odv-frame') : window;

            if (box.bottom > bottom) {
                page.scrollBy({ top: box.bottom - bottom });
            } else if (box.top < top) {
                page.scrollBy({ top: box.top - top });
            } else if (card && ! floating && ! this.expanded) {
                // La ficha va debajo del dibujo: se sube lo justo para verla, sin perder de vista la pieza.
                const below = card.getBoundingClientRect().bottom - (window.innerHeight - 12);

                if (below > 0) {
                    window.scrollBy({ top: Math.min(below, box.top - top) });
                }
            }
        },

        closeTooth() {
            this.focus = null;
            this.paint();
        },

        step(delta) {
            const order = this.rows.flat();
            const index = order.indexOf(this.focus);

            this.openTooth(order[(index + delta + order.length) % order.length]);
        },

        // Arriba y abajo: la pieza que está en la otra arcada, a la misma altura del dibujo.
        stepRow(delta) {
            const row = this.rows.findIndex((r) => r.includes(this.focus));
            const target = this.rows[row + delta];

            if (! target) {
                return;
            }

            const position = this.rows[row].indexOf(this.focus) / Math.max(1, this.rows[row].length - 1);
            this.openTooth(target[Math.round(position * (target.length - 1))]);
        },

        tapFace(zone) {
            const face = this.face(zone);

            if (! this.editable || ! face.surface) {
                return;
            }

            if (this.current?.appliesTo === 'surface') {
                this.mark(this.focus, face.surface);
            }
        },

        // Desde la ficha: lo de toda la pieza se marca en esta pieza y el pincel que había sigue igual (tocar otra
        // pieza no la marca); lo de una cara o de un tramo queda en el pincel, y tocarlo de nuevo lo suelta.
        addHere(code) {
            const condition = this.catalog[code];

            if (! condition || this.focus === null) {
                return;
            }

            if (condition.appliesTo === 'tooth') {
                const brush = [this.tool, this.state, this.abbreviation, this.spanStart];

                if (this.tool !== code) {
                    this.select(code);
                }

                this.mark(this.focus);
                [this.tool, this.state, this.abbreviation, this.spanStart] = brush;
                this.paint();

                return;
            }

            this.select(code);

            if (this.tool && condition.appliesTo === 'span') {
                this.spanStart = this.focus;
                this.paint();
            }
        },

        face(zone) {
            return this.faces[zone] ?? { surface: '', state: 'none' };
        },

        treat(ids, code) {
            this.$wire.markTreated(ids, this.savedAbbreviation(this.catalog[code]?.treatedAs));
        },

        // ---------- El buscador ----------

        get dictating() {
            return /\d/.test(this.query);
        },

        get matches() {
            const query = plain(this.query);
            const codes = Object.keys(this.catalog);

            if (! query) {
                return codes.filter((code) => ! this.frequent.includes(code) && ! this.favorites.includes(code));
            }

            return codes.filter((code) => {
                const condition = this.catalog[code];
                const words = [condition.name, ...condition.abbreviations, ...Object.values(condition.meanings).flat()].map(plain);

                return words.some((word) => word.includes(query) || word.split(' ').some((part) => part.startsWith(query)));
            }).slice(0, 8);
        },

        onQuery() {
            this.searching = true;
            this.highlighted = 0;
            clearTimeout(this.sendTimer);

            // Con un número de pieza es lo escrito o dictado: el servidor lo entiende y lo resalta en el dibujo.
            this.sendTimer = setTimeout(() => {
                const text = this.dictating ? this.query : '';

                if (text !== (this.$wire.dictation ?? '')) {
                    this.$wire.$set('dictation', text);
                }
            }, this.dictating ? 350 : 0);
        },

        moveMatch(delta) {
            const count = this.matches.length;

            if (count) {
                this.highlighted = (this.highlighted + delta + count) % count;
            }
        },

        submitQuery() {
            if (! this.query.trim()) {
                return;
            }

            if (this.dictating) {
                clearTimeout(this.sendTimer);
                this.$wire.addDictated(this.query).then(() => {
                    this.query = this.$wire.dictation ?? '';
                });

                return;
            }

            this.pick(this.matches[this.highlighted]);
        },

        pick(code) {
            if (code) {
                this.select(code === this.tool ? null : code);
            }

            this.query = '';
            this.searching = false;
        },

        clearQuery() {
            this.query = '';
            this.searching = false;
            this.onQuery();
            this.$refs.omni?.blur();
        },

        // ---------- Filtros y pantalla completa ----------

        setFilter(filter) {
            this.filter = this.filter === filter ? null : filter;
        },

        toggleExpanded() {
            this.expanded = ! this.expanded;
            this.lockPage(this.expanded);
            this.$nextTick(() => {
                this.centerSheet();
                this.$refs.sheet?.focus({ preventScroll: true });
            });
        },

        lockPage(locked) {
            document.documentElement.classList.toggle('odv-locked', locked);
        },

        // En el celular el dibujo es más ancho que la pantalla: se abre centrado en la línea media.
        centerSheet() {
            const scroller = this.$root.querySelector('.odv-scroll');

            if (scroller && scroller.scrollWidth > scroller.clientWidth) {
                scroller.scrollLeft = (scroller.scrollWidth - scroller.clientWidth) / 2;
            }
        },

        // ---------- Teclado ----------

        onKey(event) {
            if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            const key = event.key;

            if (typing(event.target)) {
                return;
            }

            if (key === 'Escape') {
                if (this.focus !== null) {
                    this.closeTooth();
                } else if (this.tool) {
                    this.select(null);
                } else if (this.expanded) {
                    this.toggleExpanded();
                } else {
                    return;
                }
            } else if (key === '/') {
                this.$refs.omni?.focus();
            } else if (key === 'ArrowRight' || key === 'ArrowLeft') {
                this.focus === null ? this.openTooth(this.rows[0]?.[0]) : this.step(key === 'ArrowRight' ? 1 : -1);
            } else if (key === 'ArrowUp' || key === 'ArrowDown') {
                this.focus === null ? this.openTooth(this.rows[0]?.[0]) : this.stepRow(key === 'ArrowDown' ? 1 : -1);
            } else if (/^\d$/.test(key)) {
                // Dos números seguidos abren esa pieza: 3 y 6, la 36.
                this.digits = (this.digits + key).slice(-2);
                clearTimeout(this.digitsTimer);
                this.digitsTimer = setTimeout(() => (this.digits = ''), 900);

                if (this.digits.length === 2 && this.teeth[Number(this.digits)]) {
                    this.openTooth(Number(this.digits));
                    this.digits = '';
                }
            } else if (key === 'Enter' && this.focus !== null && this.current && this.current.appliesTo !== 'surface') {
                this.mark(this.focus);
            } else if (this.focus !== null && /^[mdvoilp]$/i.test(key)) {
                this.keyFace(key.toUpperCase());
            } else if (key === 'f' || key === 'F') {
                this.toggleExpanded();
            } else {
                return;
            }

            event.preventDefault();
        },

        // M, D, V, O o I, L o P: la cara de la pieza abierta, con el pincel de superficie.
        keyFace(letter) {
            if (this.current?.appliesTo !== 'surface') {
                return;
            }

            const surfaces = ZONES.map((zone) => this.face(zone).surface);
            const inner = surfaces.find((s) => s === 'P' || s === 'L');
            const center = surfaces.find((s) => s === 'O' || s === 'I');
            const surface = { L: inner, P: inner, O: center, I: center }[letter] ?? letter;

            if (surfaces.includes(surface)) {
                this.mark(this.focus, surface);
            }
        },

        // ---------- Sugerencias para el diagnóstico y el plan ----------

        recount() {
            const checked = [...this.$root.querySelectorAll('[data-suggestion]:checked:not(:disabled)')];

            this.picked = checked.length;
            this.planTotal = checked.reduce((total, el) => total + Number(el.dataset.price || 0), 0);
        },

        money(amount) {
            return `S/ ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        },

        applySuggestions() {
            const keys = [...this.$root.querySelectorAll('[data-suggestion]:checked:not(:disabled)')].map((el) => el.value);

            if (keys.length) {
                this.$wire.applySuggestions(keys);
            }
        },

        // ---------- Pintar ----------

        surfaceEl(tooth, surface) {
            return this.$root.querySelector(`.odv-surface[data-tooth="${tooth}"][data-surface="${surface}"]`);
        },

        paint() {
            const root = this.$root;

            root.querySelectorAll('[data-shown]').forEach((el) => {
                el.setAttribute('class', el.dataset.shown);
                el.removeAttribute('data-shown');
            });
            root.querySelectorAll('[data-busy], [data-span-start], [data-focus]').forEach((el) => {
                el.removeAttribute('data-busy');
                el.removeAttribute('data-span-start');
                el.removeAttribute('data-focus');
            });

            Object.values(this.pending).forEach(({ tooth, surface, paint }) => {
                if (! surface) {
                    root.querySelector(`[data-tooth-group="${tooth}"]`)?.setAttribute('data-busy', '');

                    return;
                }

                const el = this.surfaceEl(tooth, surface);

                if (el) {
                    el.dataset.shown = el.getAttribute('class');
                    el.setAttribute('class', el.dataset.shown.replace(/\bis-(bad|good|none)\b/, `is-${paint}`));
                }
            });

            if (this.spanStart !== null) {
                root.querySelector(`[data-tooth-group="${this.spanStart}"]`)?.setAttribute('data-span-start', '');
            }

            // La ficha copia los colores de las caras del dibujo, con lo pendiente ya pintado.
            if (this.focus !== null) {
                root.querySelector(`[data-tooth-group="${this.focus}"]`)?.setAttribute('data-focus', '');
                this.faces = Object.fromEntries(ZONES.map((zone) => {
                    const el = root.querySelector(`.odv-surface[data-tooth="${this.focus}"][data-zone="${zone}"]`);

                    return [zone, { surface: el?.dataset.surface ?? '', state: el?.getAttribute('class').match(/\bis-(bad|good|none)\b/)?.[1] ?? 'none' }];
                }));
            }

            this.recount();
        },
    }));

    window.Alpine ? register() : document.addEventListener('alpine:init', register);
})();
