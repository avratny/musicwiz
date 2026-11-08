// Globaler Player: Store siehe resources/views/partials/player-store.blade.php (inline, vor Alpine-Start).

// Leertaste: Play/Pause, solange kein Eingabefeld aktiv ist. Auch auf Buttons – sonst löst die Leertaste den zuletzt
// geklickten Button erneut aus (z. B. derselbe Stern noch mal = Bewertung entfernt). Buttons gehen per Tastatur mit Enter.
const typing = 'textarea, select, [contenteditable], input:not([type=checkbox], [type=radio], [type=range], [type=button], [type=submit])';
document.addEventListener('keydown', (e) => {
    if (e.code !== 'Space' || e.target.closest(typing)) return;
    const p = window.Alpine?.store('player');
    if (!p?.track) return;
    e.preventDefault();
    if (!e.repeat) p.toggle();
});
// Buttons lösen bei der Leertaste erst beim Loslassen aus – auch das unterdrücken
document.addEventListener('keyup', (e) => {
    if (e.code === 'Space' && !e.target.closest(typing) && window.Alpine?.store('player')?.track) e.preventDefault();
});
