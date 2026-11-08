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

// Rückfragen (wire:confirm) als eigenes Modal statt Browser-Dialog: Livewire fragt über el.__livewire_confirm(ausführen,
// abbrechen) – das ersetzen wir nach Livewires eigener Einrichtung. Das Modal liegt im Layout (partials/confirm-modal).
// Optional am Element: data-confirm-label="Löschen" (Text des Knopfs), data-confirm-danger (roter Knopf).
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive.value !== 'confirm') return;
        const message = (directive.expression || 'Wirklich ausführen?').replaceAll('\\n', '\n');
        el.__livewire_confirm = (action, instead) => {
            instead(); // Klick jetzt anhalten – ausgeführt wird erst nach „OK“ im Modal
            window.dispatchEvent(new CustomEvent('mw-confirm', {
                detail: {
                    message,
                    label: el.dataset.confirmLabel || null,
                    danger: el.hasAttribute('data-confirm-danger') || /endgültig/i.test(message),
                    run: action,
                },
            }));
        };
    });
});
