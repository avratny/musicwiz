#!/usr/bin/env bash
# MusicWiz – Entwicklungscontainer starten und stoppen
set -euo pipefail
cd "$(dirname "$0")"

export PUID="${PUID:-$(id -u)}"
export PGID="${PGID:-$(id -g)}"
COMPOSE=(docker compose -f docker-compose.yml)

usage() {
  cat <<USAGE
Verwendung: ./dev.sh <befehl>

  start      Container bauen und starten (http://localhost:\${APP_PORT:-8000})
  stop       Container stoppen
  restart    Neu starten
  status     Laufende Container anzeigen
  logs       Logs verfolgen
  shell      Shell im App-Container
  artisan …  artisan-Befehl im Container ausführen
  composer … composer im Container ausführen
  test       Tests ausführen
  setup      Erstinstallation (composer, .env, Datenbank)
USAGE
}

ensure_setup() {
  [ -f .env ] || cp .env.example .env
  mkdir -p storage/app
  "${COMPOSE[@]}" up -d db
  if [ ! -d vendor ]; then "${COMPOSE[@]}" run --rm --no-deps app composer install; fi
  grep -q '^APP_KEY=base64' .env || "${COMPOSE[@]}" run --rm --no-deps app php artisan key:generate
  "${COMPOSE[@]}" run --rm app php artisan migrate --force
}

cmd="${1:-help}"; shift || true
case "$cmd" in
  start)
    "${COMPOSE[@]}" build
    ensure_setup
    "${COMPOSE[@]}" up -d
    echo "MusicWiz läuft auf http://localhost:${APP_PORT:-8000}"
    ;;
  stop)     "${COMPOSE[@]}" down ;;
  restart)  "${COMPOSE[@]}" down; "$0" start ;;
  status)   "${COMPOSE[@]}" ps ;;
  logs)     "${COMPOSE[@]}" logs -f "$@" ;;
  shell)    "${COMPOSE[@]}" exec app bash ;;
  artisan)  "${COMPOSE[@]}" exec app php artisan "$@" ;;
  composer) "${COMPOSE[@]}" run --rm --no-deps app composer "$@" ;;
  test)     "${COMPOSE[@]}" run --rm -e DB_DATABASE=musicwiz_test app php artisan test "$@" ;;
  setup)    "${COMPOSE[@]}" build; ensure_setup ;;
  *)        usage ;;
esac
