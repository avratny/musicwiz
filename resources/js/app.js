// MusicWiz – Alpine (kommt mit Livewire): Player, Fortschritt, Bearbeiten in der Tabelle.
// Die Stores leben über Seitenwechsel (wire:navigate) hinweg – die Musik spielt weiter.

const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const formatTime = (s) => {
    s = Math.max(0, Math.floor(s || 0));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
};

// Cover fehlt: leere Fläche statt kaputtem Bild – bleibt anklickbar (Cover ändern)
window.noCover = (img) => {
    img.onerror = null;
    img.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
};

// Rückfragen als Modal statt Browser-Dialog: Knopf mit data-confirm="Text" (optional data-confirm-title, -ok, -danger).
// Der Klick wird vor allen anderen Handlern abgefangen und nach „OK“ noch einmal ausgelöst – wire:click bleibt, wie es ist.
if (!window.__confirmListener) {
    window.__confirmListener = true;
    document.addEventListener('click', (e) => {
        const el = e.target.closest?.('[data-confirm]');
        if (!el || el.disabled) return;
        if (el.__confirmed) {
            delete el.__confirmed;
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        window.Alpine?.store('confirm').ask(el);
    }, true);
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.store('confirm', {
        open: false,
        title: '',
        text: '',
        ok: '',
        danger: false,
        el: null,
        ask(el) {
            this.el = el;
            this.title = el.dataset.confirmTitle || 'Bitte bestätigen';
            this.text = el.dataset.confirm;
            this.ok = el.dataset.confirmOk || 'Fortfahren';
            this.danger = 'confirmDanger' in el.dataset;
            this.open = true;
        },
        accept() {
            const el = this.el;
            this.close();
            if (el?.isConnected) {
                el.__confirmed = true;
                el.click();
            }
        },
        close() {
            this.open = false;
            this.el = null;
        },
    });

    Alpine.store('player', {
        queue: [],
        index: -1,
        current: null,
        playing: false,
        time: 0,
        duration: 0,
        volume: parseFloat(localStorage.getItem('mw.volume') ?? '0.8'),
        audio: null,

        init() {
            this.audio = new Audio();
            this.audio.preload = 'auto';
            this.audio.volume = this.volume;
            this.audio.addEventListener('timeupdate', () => (this.time = this.audio.currentTime));
            this.audio.addEventListener('loadedmetadata', () => (this.duration = this.audio.duration || this.current?.duration || 0));
            this.audio.addEventListener('play', () => (this.playing = true));
            this.audio.addEventListener('pause', () => (this.playing = false));
            this.audio.addEventListener('ended', () => this.next());
        },
        play(queue, index = 0) {
            this.queue = queue;
            this.index = index;
            this.load();
        },
        load() {
            const t = this.queue[this.index];
            if (!t) return;
            this.current = t;
            this.time = 0;
            this.duration = t.duration || 0;
            this.audio.src = '/audio/' + t.id;
            this.audio.play().catch(() => {});
        },
        toggle() {
            if (!this.current) return;
            this.audio.paused ? this.audio.play() : this.audio.pause();
        },
        next() {
            if (this.index < this.queue.length - 1) {
                this.index++;
                this.load();
            } else {
                this.playing = false;
            }
        },
        prev() {
            if (this.audio.currentTime > 3 || this.index === 0) {
                this.audio.currentTime = 0;
            } else {
                this.index--;
                this.load();
            }
        },
        seek(value) {
            this.audio.currentTime = value;
        },
        setVolume(value) {
            this.volume = this.audio.volume = value;
            localStorage.setItem('mw.volume', value);
        },
        isCurrent(id) {
            return this.current?.id === id;
        },
        format: formatTime,
    });

    // Hintergrundvorgänge (Einlesen, Abgleich …) und Zahlen der Navigation – wird regelmäßig abgefragt
    // ab so vielen laufenden Vorgängen: eine Sammelzeile statt je einer Zeile (aufklappbar)
    const ACTIVITY_GROUP_FROM = 3;

    Alpine.store('activity', {
        items: window.mwInitial?.activities ?? [],
        counts: window.mwInitial?.counts ?? {},
        timer: null,
        expanded: localStorage.getItem('mw.activityExpanded') === '1',

        get runningItems() {
            return this.items.filter((a) => a.running);
        },
        // viele laufen gleichzeitig: zusammenfassen (Sammelzeile), aufgeklappt zusätzlich die Einzelzeilen
        get grouped() {
            return this.runningItems.length >= ACTIVITY_GROUP_FROM;
        },
        // sichtbare Zeilen: Einzelzeilen bzw. Sammelzeile (+ Einzelzeilen, wenn aufgeklappt) und kurz die fertigen
        get shown() {
            if (!this.grouped) return this.items;
            return this.expanded ? this.items : this.items.filter((a) => !a.running);
        },
        // Höhe der Leiste in Zeilen – für den Abstand von Seitenleiste, Inhalt und Flyout
        get rows() {
            return this.shown.length + (this.grouped ? 1 : 0);
        },
        toggle() {
            this.expanded = !this.expanded;
            localStorage.setItem('mw.activityExpanded', this.expanded ? '1' : '0');
        },

        init() {
            this.schedule(this.running ? 1000 : 5000);
        },
        get running() {
            return this.items.some((a) => a.running);
        },
        isRunning(key) {
            return this.items.some((a) => a.key === key && a.running);
        },
        schedule(ms) {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.poll(), ms);
        },
        async poll() {
            try {
                const res = await fetch('/activity', { headers: { Accept: 'application/json' } });
                const json = await res.json();
                const before = Object.fromEntries(this.items.map((a) => [a.key, a.running]));
                this.items = json.activities;
                this.counts = json.counts;
                // gerade fertig geworden: Seiten neu zeichnen lassen (z. B. nach dem Einlesen)
                for (const a of this.items) {
                    if (before[a.key] && !a.running) window.Livewire?.dispatch(a.key + '-finished');
                }
            } catch (e) {
                // Server kurz nicht erreichbar: später erneut
            }
            this.schedule(this.running ? 1000 : 5000);
        },
        kick() {
            this.schedule(300);
        },
        async stop(key) {
            await fetch('/activity/' + key + '/stop', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
            this.kick();
        },
        percent(a) {
            return a.total ? Math.min(100, Math.round((a.done / a.total) * 100)) : null;
        },
    });

    // Flyout rechts: Breite an der linken Kante ziehbar, für alle Flyouts gleich und im Browser gespeichert
    const FLYOUT_DEFAULT = 460;
    Alpine.store('flyout', { width: parseInt(localStorage.getItem('mw.flyoutWidth') ?? '', 10) || FLYOUT_DEFAULT });
    Alpine.data('flyoutPanel', () => ({
        resizing: false,
        get style() {
            // unten Platz für Player und Hintergrundvorgänge
            return `width: ${Alpine.store('flyout').width}px; bottom: ${64 + Alpine.store('activity').rows * 31}px`;
        },
        clamp(width) {
            return Math.round(Math.min(window.innerWidth * 0.85, Math.max(360, width)));
        },
        startResize() {
            this.resizing = true;
            document.body.classList.add('select-none', 'cursor-col-resize');
            const move = (ev) => (Alpine.store('flyout').width = this.clamp(window.innerWidth - ev.clientX));
            const up = () => {
                this.resizing = false;
                document.body.classList.remove('select-none', 'cursor-col-resize');
                window.removeEventListener('mousemove', move);
                window.removeEventListener('mouseup', up);
                localStorage.setItem('mw.flyoutWidth', Alpine.store('flyout').width);
            };
            window.addEventListener('mousemove', move);
            window.addEventListener('mouseup', up);
        },
        resetWidth() {
            Alpine.store('flyout').width = FLYOUT_DEFAULT;
            localStorage.setItem('mw.flyoutWidth', FLYOUT_DEFAULT);
        },
    }));

    // Genre per Volltextsuche (Sonstiges › Genre, Genres › Nicht zugeordnet, Dialog „Neues Genre“) – jedes Wort irgendwo
    // in Name, Pfad im Baum oder Synonym (ohne Groß/klein und Akzente). Klick oder Enter auf einen Treffer übernimmt
    // sofort: ohne opts.take als Genre der Lieder (takeGenre), sonst opts.take(treffer); opts.maxLevel begrenzt die Ebene.
    const genreSearchText = (s) => s.toLowerCase().normalize('NFD').replace(/\p{Mn}+/gu, '').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    Alpine.data('genrePicker', (key, trackId, opts = {}) => ({
        q: '',
        open: false,
        active: 0,
        pos: '',
        init() {
            this.$watch('open', (open) => open && this.place());
        },
        // unter dem Eingabefeld (fest am Fenster) – reicht der Platz unten nicht, darüber
        place() {
            if (!this.open || !this.$refs.input) return;
            const r = this.$refs.input.getBoundingClientRect();
            // unten liegen Player und Fortschrittsleiste (wie das Padding von <main>)
            const below = window.innerHeight - (96 + Alpine.store('activity').rows * 31) - r.bottom - 8;
            const above = r.top - 8;
            const down = below >= 160 || below >= above;
            const height = Math.max(80, Math.min(320, down ? below : above));
            const top = down ? r.bottom + 4 : Math.max(8, r.top - 4 - height);
            this.pos = `top: ${top}px; left: ${r.left}px; width: ${Math.max(r.width, 280)}px; max-height: ${height}px`;
        },
        get index() {
            const el = document.getElementById('genre-index');
            if (!el) return [];
            if (el._parsed?.src !== el.textContent) el._parsed = { src: el.textContent, list: JSON.parse(el.textContent) };
            return el._parsed.list;
        },
        get results() {
            const q = genreSearchText(this.q);
            if (q.length < 2) return [];
            const words = q.split(' ');
            const rank = (g) => {
                if (g.name === q) return 0;
                if (g.name.startsWith(q)) return 1;
                if ((' ' + g.name).includes(' ' + words[0])) return 2;
                if (words.every((w) => g.name.includes(w))) return 3;
                return 4;
            };
            return this.index
                .filter((g) => (!opts.maxLevel || g.level <= opts.maxLevel) && words.every((w) => g.text.includes(w)))
                .map((g) => ({ ...g, rank: rank(g) }))
                .sort((a, b) => a.rank - b.rank || a.path.length - b.path.length || a.path.localeCompare(b.path))
                .slice(0, 15);
        },
        take(r) {
            this.open = false;
            if (opts.take) {
                this.q = r.path;
                opts.take(r);
            } else {
                this.q = r.value;
                this.$wire.takeGenre(key, trackId, r.value);
            }
        },
        keys(e) {
            if (!this.open || !this.results.length) return;
            if (e.key === 'ArrowDown') this.active = (this.active + 1) % this.results.length;
            else if (e.key === 'ArrowUp') this.active = (this.active - 1 + this.results.length) % this.results.length;
            else if (e.key === 'Enter') this.take(this.results[this.active] ?? this.results[0]);
            else if (e.key === 'Escape') this.open = false;
            else if (e.key === 'Tab') return void (this.open = false);
            else return;
            e.preventDefault();
        },
    }));

    // Interpreten › Ähnlich: eine Gruppe – richtige Schreibweise wählen, Mitglieder ab-/anwählen, eigene Schreibweise.
    // Die oberste Gruppe (active) hört auf die Tastatur: 1–9 wählt, Enter übernimmt das Fazit, Z = zusammenführen,
    // V = verschieden, S = später.
    Alpine.data('similarGroup', (members, active, key, verdict) => ({
        members,
        // vorbelegt aus dem Fazit: Ziel, Schreibweise laut MusicBrainz, abgewählt, wer ein anderer Interpret ist
        target: verdict?.target ?? members[0].id,
        checked: members.map((m) => m.id).filter((id) => !(verdict?.uncheck ?? []).includes(id)),
        name: verdict?.name ?? '',
        busy: false,
        get ref() {
            return this.name.trim() || this.members.find((m) => m.id === this.target).name;
        },
        // [gleicher Anfang, Unterschied, gleiches Ende] gegenüber der gewählten Schreibweise
        parts(index) {
            const a = this.members[index].name;
            const b = this.ref;
            let p = 0;
            while (p < a.length && p < b.length && a[p] === b[p]) p++;
            let s = 0;
            while (s < a.length - p && s < b.length - p && a[a.length - 1 - s] === b[b.length - 1 - s]) s++;
            return [a.slice(0, p), a.slice(p, a.length - s), a.slice(a.length - s)];
        },
        pick(id) {
            this.target = id;
            if (!this.checked.includes(id)) this.checked.push(id);
        },
        toggle(id) {
            if (id === this.target) return;
            this.checked = this.checked.includes(id) ? this.checked.filter((c) => c !== id) : [...this.checked, id];
        },
        run(call) {
            if (this.busy) return;
            this.busy = true;
            call().finally(() => (this.busy = false));
        },
        merge() {
            if (this.checked.length < 2 && !this.name.trim()) return;
            const keep = this.members.map((m) => m.id).filter((id) => !this.checked.includes(id));
            this.run(() => this.$wire.mergeSimilar(this.target, this.checked.filter((id) => id !== this.target), keep, this.name));
        },
        different() {
            this.run(() => this.$wire.notSame(this.members.map((m) => m.id)));
        },
        later() {
            this.run(() => this.$wire.postpone(key));
        },
        key(e) {
            if (!active || e.metaKey || e.ctrlKey || e.altKey || e.target.closest('input[type=text], input[type=search], textarea, select, [contenteditable]')) return;
            if (/^[1-9]$/.test(e.key) && this.members[e.key - 1]) {
                this.pick(this.members[e.key - 1].id);
            } else if (e.key === 'Enter' && !e.target.closest('button')) {
                if (verdict?.action === 'merge') this.merge();
                else if (verdict?.action === 'distinct') this.different();
                else return; // unklar: selbst entscheiden (Z/V)
            } else if (e.key === 'z' || e.key === 'Z') {
                this.merge();
            } else if (e.key === 'v' || e.key === 'V') {
                this.different();
            } else if (e.key === 's' || e.key === 'S') {
                this.later();
            } else {
                return;
            }
            e.preventDefault();
        },
    }));

    // Interpreten › Kombis: Teile bearbeiten, trennen oder ganz lassen. Die oberste Karte hört auf die Tastatur:
    // Enter übernimmt das Fazit, T = trennen, G = bleibt ganz, M = ganz + Mitglieder, S = später.
    const CHECK_SERVICES = { musicbrainz: 'MusicBrainz', spotify: 'Spotify', discogs: 'Discogs', audiodb: 'TheAudioDB', lastfm: 'Last.fm' };
    // 1234567 → „1,2 Mio.“, 45300 → „45 Tsd.“
    const compact = (n) => n >= 1e6 ? (n / 1e6).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' Mio.' : n >= 1e4 ? Math.round(n / 1e3).toLocaleString('de-DE') + ' Tsd.' : n.toLocaleString('de-DE');
    const MB_TYPES = { Person: 'Person', Group: 'Gruppe', Orchestra: 'Orchester', Choir: 'Chor', Character: 'Figur', Other: 'Sonstiges' };
    Alpine.data('comboCard', (name, parts, known, active, verdict, checks, hasMembers) => ({
        name,
        parts: [...parts],
        known,
        checks,
        busy: false,
        checked(n) {
            return !!this.checks[(n ?? '').trim()];
        },
        // wie viele Interpreten bei MusicBrainz genau so heißen
        namesakes(n) {
            return this.checks[(n ?? '').trim()]?.musicbrainz?.namesakes ?? 0;
        },
        // eigene Lieder des Teils: allein im Interpret-Tag bzw. mit anderen (feat., vs., Gast)
        ownTracks(part) {
            const p = (part ?? '').trim();
            const k = this.known[p];
            if (!k) return p ? 'wird neu angelegt' : '';
            const n = (x) => x.toLocaleString('de-DE');
            const counts = k.alone || k.others ? n(k.alone) + ' allein · ' + n(k.others) + ' mit anderen' : 'keine eigenen Lieder';
            return (k.name !== p ? '→ ' + k.name + ': ' : '') + counts;
        },
        // Namensprüfung je Dienst: ok (so bekannt), similar (ähnlich/als Schreibweise), missing, error
        badges(n) {
            const c = this.checks[(n ?? '').trim()];
            if (!c) return [];
            return Object.entries(CHECK_SERVICES).filter(([s]) => c[s] && c[s].status !== 'uebersprungen').map(([s, label]) => {
                const r = c[s];
                const state = r.status === 'fehler' ? 'error' : r.status !== 'ok' ? 'missing' : ['exakt', 'gross_klein', 'zeichen'].includes(r.match) ? 'ok' : 'similar';
                const extra = [r.releases != null ? r.releases.toLocaleString('de-DE') + ' Veröffentl.' : '', r.popularity != null ? 'Beliebtheit ' + r.popularity : '',
                    r.listeners != null ? r.listeners.toLocaleString('de-DE') + ' Hörer' : ''].filter(Boolean);
                const title = state === 'error' ? label + ': Fehler bei der Abfrage' + (r.message ? ' – ' + r.message : '') + ' (wird beim nächsten „Dienste fragen“ wiederholt)' : state === 'missing' ? label + ': nicht gefunden'
                    : label + ': „' + r.found + '“' + (state === 'similar' ? ' (ähnlich)' : '') + (extra.length ? ' – ' + extra.join(', ') : '');
                const short = [MB_TYPES[r.type] ?? r.type ?? '', r.releases != null ? r.releases.toLocaleString('de-DE') : '', r.popularity != null ? '♥ ' + r.popularity : '', r.listeners != null ? compact(r.listeners) + ' Hörer' : ''];
                return { service: s, label, state, title, url: r.url, type: short.filter(Boolean).join(' · ') };
            });
        },
        run(call) {
            if (this.busy) return;
            this.busy = true;
            call().finally(() => (this.busy = false));
        },
        split() {
            if (this.parts.filter((p) => p.trim()).length < 2) return;
            this.run(() => this.$wire.split(name, this.parts));
        },
        whole() {
            this.run(() => this.$wire.keepWhole(name));
        },
        withMembers() {
            if (!hasMembers) return; // nur bei Gruppen mit bekannten Mitgliedern
            this.run(() => this.$wire.keepWithMembers(name, this.parts));
        },
        later() {
            this.run(() => this.$wire.postpone(name));
        },
        key(e) {
            if (!active || e.metaKey || e.ctrlKey || e.altKey || e.target.closest('input[type=text], input[type=search], textarea, select, [contenteditable]')) return;
            if (e.key === 'Enter' && !e.target.closest('button')) {
                if (verdict === 'split') this.split();
                else if (verdict === 'whole') this.whole();
                else if (verdict === 'members') this.withMembers();
                else return; // unklar: selbst entscheiden (T/G)
            } else if (e.key === 'm' || e.key === 'M') {
                this.withMembers();
            } else if (e.key === 't' || e.key === 'T') {
                this.split();
            } else if (e.key === 'g' || e.key === 'G') {
                this.whole();
            } else if (e.key === 's' || e.key === 'S') {
                this.later();
            } else {
                return;
            }
            e.preventDefault();
        },
    }));

    // Tabelle: Klick wählt ein Lied (Flyout), ⌘/Strg+Klick fügt hinzu, Shift+Klick wählt einen Bereich,
    // Doppelklick bearbeitet die Zelle (Enter/Tab speichert, Esc bricht ab). Die Auswahl reagiert sofort im Browser,
    // der Server (Flyout) folgt kurz danach.
    Alpine.data('trackTable', () => ({
        clickTimer: null,

        // Abspielliste steht einmal je Album (tbody data-queue) statt in jeder Zeile – hält die Seite klein
        playFrom(el, index) {
            this.$store.player.play(JSON.parse(el.closest('tbody').dataset.queue), index);
        },
        pushTimer: null,
        sel: [],
        anchor: null,

        init() {
            // Startauswahl einmalig vom Server (das x-data-Attribut bleibt fest, sonst startet Alpine beim Neuzeichnen neu)
            this.sel = [...(this.$wire.selection ?? [])];
            this.anchor = this.sel[0] ?? null;
        },

        isSel(id) {
            return this.sel.includes(id);
        },
        rowIds(scope = this.$root) {
            return [...scope.querySelectorAll('tr[data-id]')].map((tr) => Number(tr.dataset.id));
        },
        push() {
            clearTimeout(this.pushTimer);
            this.pushTimer = setTimeout(() => this.$wire.select(this.sel), 120);
        },
        toggle(id) {
            this.sel = this.isSel(id) ? this.sel.filter((x) => x !== id) : [...this.sel, id];
            this.anchor = id;
            this.push();
        },
        toggleGroup(el) {
            const ids = this.rowIds(el.closest('tbody'));
            const all = ids.every((id) => this.isSel(id));
            this.sel = all ? this.sel.filter((id) => !ids.includes(id)) : [...new Set([...this.sel, ...ids])];
            this.push();
        },
        // Klick auf den Album-Kopf: genau dieses Album auswählen (Flyout)
        selectGroup(el) {
            this.sel = this.rowIds(el.closest('tbody'));
            this.anchor = this.sel[0] ?? null;
            this.push();
        },
        groupState(el) {
            const ids = this.rowIds(el.closest('tbody'));
            const n = ids.filter((id) => this.isSel(id)).length;
            return n === 0 ? 'none' : n === ids.length ? 'all' : 'some';
        },
        clear() {
            this.sel = [];
            this.push();
        },

        rowClick(e) {
            if (e.target.closest('button, input, a, textarea, select, [data-noflyout]')) return;
            const tr = e.target.closest('tr[data-id]');
            if (!tr) return;
            const id = Number(tr.dataset.id);
            if (e.shiftKey && this.anchor !== null) {
                const ids = this.rowIds();
                const [a, b] = [ids.indexOf(this.anchor), ids.indexOf(id)].sort((x, y) => x - y);
                const range = a < 0 ? [id] : ids.slice(a, b + 1);
                this.sel = e.metaKey || e.ctrlKey ? [...new Set([...this.sel, ...range])] : range;
                window.getSelection()?.removeAllRanges();
                this.push();
                return;
            }
            if (e.metaKey || e.ctrlKey) {
                this.toggle(id);
                return;
            }
            clearTimeout(this.clickTimer);
            this.clickTimer = setTimeout(() => {
                this.sel = [id];
                this.anchor = id;
                this.push();
            }, 220);
        },

        edit(e) {
            clearTimeout(this.clickTimer);
            const td = e.target.closest('td[data-field]');
            if (!td || td.querySelector('input') || e.target.closest('select')) return;
            const tr = td.closest('tr[data-id]');
            const old = td.dataset.value ?? '';
            const html = td.innerHTML;
            const input = document.createElement('input');
            input.value = old;
            input.className = 'cell-input';
            td.innerHTML = '';
            td.appendChild(input);
            input.focus();
            input.select();

            let finished = false;
            const finish = async (save) => {
                if (finished) return;
                finished = true;
                if (!save || input.value === old) {
                    td.innerHTML = html;
                    return;
                }
                td.innerHTML = '<span class="opacity-50">' + escapeHtml(input.value) + '</span>';
                try {
                    const result = await this.$wire.saveField(Number(tr.dataset.id), td.dataset.field, input.value);
                    const value = result?.value ?? '';
                    td.dataset.value = value;
                    td.textContent = value;
                    td.classList.add('cell-saved');
                    setTimeout(() => td.classList.remove('cell-saved'), 1200);
                } catch (err) {
                    td.innerHTML = html;
                }
            };
            input.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter') finish(true);
                if (ev.key === 'Escape') finish(false);
                if (ev.key === 'Tab') {
                    ev.preventDefault();
                    const cells = [...this.$root.querySelectorAll('td[data-field]')];
                    const next = cells[cells.indexOf(td) + (ev.shiftKey ? -1 : 1)];
                    finish(true).then(() => next && this.edit({ target: next }));
                }
            });
            input.addEventListener('blur', () => finish(true));
        },
    }));
});

// Zahlen in der Navigation gleich nachladen: nach jedem Seitenwechsel und nach jeder Aktion (sonst erst nach bis zu 5 s)
let countsTimer = null;
const refreshCounts = () => {
    clearTimeout(countsTimer);
    countsTimer = setTimeout(() => window.Alpine?.store('activity')?.poll(), 250);
};
document.addEventListener('livewire:navigated', refreshCounts);
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed }) => succeed(refreshCounts));
});
