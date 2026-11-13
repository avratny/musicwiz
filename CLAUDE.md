# MusicWiz (Neuaufbau ab 0.4)

Laravel 13 + Livewire 4 + Tailwind 4, **MariaDB** (eigener Container `db`). Lokal gibt es kein PHP: alles über `./dev.sh` (Docker).
- Tests: `./dev.sh test` (eigene DB `musicwiz_test`) · Format: `docker compose exec app vendor/bin/pint`
- UI-Texte auf Deutsch. Alter Stand 0.3.42 zum Nachlesen: `~/Projekte/musicwiz_old` (Git-Worktree, Tag `v0.3.42`).
- Wir bauen langsam und gemeinsam auf: nichts über das Besprochene hinaus bauen, außer es ist eine Abhängigkeit.
- Grundsatz: Tag-Änderungen nur in der DB (`tracks`), jede Änderung zusätzlich in `tag_changes` (manuell/System).
  In Dateien geschrieben wird (noch) nicht.
- Einlesen: `tools/tagreader.py` (mutagen, parallel, nur neue/geänderte Dateien) → `App\Support\Scanner` (Pakete à 500).
- `demofiles/` sind echte Musikdateien (48 GB) – nicht ins Docker-Build-Kontext, Tests legen eigene Dateien an.
- Production läuft gepinnt auf Image `:0.3.42` – Push auf `main` baut `latest` (nicht deployen, bevor der Betrieb auf MariaDB umgestellt ist).
