#!/bin/sh
# MusicWiz – Start im Betrieb (Image aus docker/Dockerfile, Ziel prod)
set -e
cd /app

# Als root gestartet: Datenverzeichnisse dem Besitzer des Shares (PUID/PGID) geben und als dieser weiterlaufen,
# damit neue und verschobene Dateien nicht root gehören
if [ "$(id -u)" = "0" ]; then
    PUID="${PUID:-1000}"
    PGID="${PGID:-1000}"
    mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
    chown -R "$PUID:$PGID" storage bootstrap/cache /data /config /usr/local/bin/yt-dlp
    exec setpriv --reuid="$PUID" --regid="$PGID" --clear-groups "$0" "$@"
fi

mkdir -p storage/app/cache storage/framework/sessions storage/framework/views storage/logs

# Schlüssel einmal erzeugen und im Datenverzeichnis aufheben (bleibt bei Updates gleich)
if [ -z "${APP_KEY:-}" ]; then
    KEYFILE=storage/app/app.key
    [ -s "$KEYFILE" ] || php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEYFILE"
    APP_KEY="$(cat "$KEYFILE")"
    export APP_KEY
fi

DB="${DB_DATABASE:-/app/storage/app/musicwiz.sqlite}"
mkdir -p "$(dirname "$DB")"
[ -f "$DB" ] || touch "$DB"

# Datenbank nur im App-Container aktualisieren (Worker warten darauf, sonst laufen Migrationen doppelt)
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    php artisan migrate --force
fi
php artisan config:cache
php artisan view:cache
exec "$@"
