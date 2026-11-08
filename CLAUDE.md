# MusicWiz

Laravel 13 + Livewire 4 + Tailwind 4, SQLite. Lokal gibt es kein PHP: alles über `./dev.sh` (Docker).
- Tests: `./dev.sh test` · Format: `docker compose exec app vendor/bin/pint`
- Planung/Anforderungen: `MusicWiz – Planung.md`. UI-Texte auf Deutsch.
- Grundsatz: nie automatisch schreiben/verschieben – Änderungen laufen als Aktionen über `OperationRunner` (Verlauf, rückgängig machbar).
- `demofiles/` sind echte Musikdateien (48 GB) – nicht ins Docker-Build-Kontext, Tests legen eigene Dateien an.
