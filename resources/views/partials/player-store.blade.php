<script>
// Globaler Player: ein <audio> unten im Layout (bleibt bei wire:navigate bestehen), gesteuert über einen Alpine-Store.
document.addEventListener('alpine:init', () => {
    window.Alpine.store('player', {
        track: null, // { id, src, title, artist, album, cover, seconds }
        rating: 0, // Sterne des laufenden Titels (0 = keine)
        rateable: false,
        ticker: null,
        error: null,
        playing: false,
        time: 0,
        duration: 0,
        dragging: false, // Zeitregler wird gerade gezogen: nicht nachführen, erst beim Loslassen springen
        dragTime: 0,
        volume: Number(localStorage.getItem('mw-volume') ?? 0.8),

        audio() {
            return document.getElementById('mw-audio');
        },

        /** start: Anteil (0–1), ab dem gespielt wird – zum kurzen Anspielen */
        play(track, start = 0) {
            const a = this.audio();
            if (!a) return;
            if (this.track && this.track.id === track.id) {
                if (start > 0 && a.paused && a.duration) a.currentTime = a.duration * start;
                a.paused ? a.play() : a.pause();
                return;
            }
            this.track = track;
            this.error = null;
            this.loadRating(track.id);
            this.time = 0;
            this.duration = Number(track.seconds) || 0;
            a.src = track.src;
            a.volume = this.volume;
            if (start > 0) {
                a.addEventListener('loadedmetadata', () => { a.currentTime = a.duration * start; }, { once: true });
            }
            a.play().catch(() => {});
            this.startTicker();
            if (track.seconds !== undefined && Number(track.seconds) < 0.1) {
                this.error = 'Datei enthält keine Audiodaten (defekt oder unvollständig).';
            }
        },

        /** Position direkt vom Audio-Element lesen – unabhängig davon, ob der Browser timeupdate/durationchange zuverlässig meldet (Safari). */
        startTicker() {
            if (this.ticker) return;
            this.ticker = setInterval(() => this.sync(), 250);
        },

        sync() {
            const a = this.audio();
            if (!a || !this.track) return;
            this.time = a.currentTime || 0;
            const d = a.duration;
            this.duration = isFinite(d) && d > 0 ? d : (Number(this.track.seconds) || this.duration || 0);
            this.playing = !a.paused && !a.ended;
        },

        /** Browser konnte die Datei nicht abspielen */
        failed(a) {
            if (!this.track || !a.getAttribute('src')) return;
            const codes = { 1: 'abgebrochen', 2: 'Netzwerkfehler', 3: 'Datei ist defekt (Dekodierfehler)', 4: 'Format oder Datei wird nicht unterstützt' };
            this.error = 'Kann nicht abgespielt werden: ' + (codes[a.error?.code] ?? 'unbekannter Fehler');
            this.playing = false;
        },

        async loadRating(id) {
            this.rating = 0;
            this.rateable = false;
            try {
                const r = await fetch('/media/rating/' + id, { headers: { Accept: 'application/json' } });
                if (r.ok && this.track?.id === id) Object.assign(this, await r.json());
            } catch (e) {}
        },

        /** Bewerten (derselbe Stern noch mal = Bewertung entfernen); schreibt in die Datei, als Aktion im Verlauf */
        async rate(stars) {
            if (!this.track || !this.rateable) return;
            const id = this.track.id;
            const value = stars === this.rating ? 0 : stars;
            const before = this.rating;
            this.rating = value;
            try {
                const r = await fetch('/media/rating/' + id, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
                    body: JSON.stringify({ rating: value }),
                });
                if (!r.ok) throw new Error();
                window.Livewire?.dispatch('rating-changed');
                window.Livewire?.dispatch('activity-changed');
            } catch (e) {
                if (this.track?.id === id) this.rating = before;
            }
        },

        toggle() {
            const a = this.audio();
            if (a && this.track) a.paused ? a.play() : a.pause();
        },

        seek(seconds) {
            const a = this.audio();
            if (!a || !isFinite(seconds)) return;
            const max = isFinite(a.duration) && a.duration > 0 ? a.duration : (this.duration || seconds);
            a.currentTime = Math.max(0, Math.min(seconds, max));
            this.time = a.currentTime;
        },

        setVolume(v) {
            this.volume = v;
            localStorage.setItem('mw-volume', v);
            const a = this.audio();
            if (a) a.volume = v;
        },

        close() {
            const a = this.audio();
            if (a) { a.pause(); a.removeAttribute('src'); a.load(); }
            this.track = null;
            this.playing = false;
            clearInterval(this.ticker);
            this.ticker = null;
        },

        isCurrent(id) {
            return this.track !== null && this.track.id === id;
        },

        isPlaying(id) {
            return this.playing && this.isCurrent(id);
        },

        format(s) {
            if (!isFinite(s)) return '0:00';
            s = Math.floor(s);
            return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        },
    });
});
</script>
<script>
// Abgelaufene Sitzung (z. B. nach einem Server-Neustart): Seite still neu laden statt "This page has expired" anzuzeigen
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) {
                preventDefault();
                window.location.reload();
            }
        });
    });
});
</script>
