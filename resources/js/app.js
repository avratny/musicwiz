// Globaler Player: Store siehe resources/views/partials/player-store.blade.php (inline, vor Alpine-Start).

// Leertaste: Play/Pause, solange kein Eingabefeld aktiv ist
document.addEventListener('keydown', (e) => {
    if (e.code !== 'Space' || e.target.closest('input, textarea, select, button, [contenteditable]')) return;
    const p = window.Alpine?.store('player');
    if (p?.track) { e.preventDefault(); p.toggle(); }
});
