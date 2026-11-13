# MusicWiz – PHP (FrankenPHP) + Tag-Leser (Python/mutagen, Pillow für Cover-Fingerabdrücke, Chromaprint für akustische Fingerabdrücke), Datenbank: MariaDB (eigener Container)
FROM --platform=$BUILDPLATFORM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
RUN npm run build

FROM dunglas/frankenphp:1-php8.4-bookworm AS base

ARG PUID=1000
ARG PGID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-mutagen python3-pil python3-acoustid libchromaprint-tools unzip git ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql gd intl zip pcntl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Container läuft mit UID/GID des Shares, damit neue Dateien nicht root gehören
RUN groupadd -o -g ${PGID} app && useradd -o -u ${PUID} -g ${PGID} -m app \
    && setcap -r /usr/local/bin/frankenphp || true \
    && chown -R app:app /data /config /app

# Speichergrenze je PHP-Prozess; per .env änderbar
# Zeitgrenze je Seitenaufruf als Sicherheitsnetz (lange Arbeiten laufen im Hintergrund-Worker ohne Grenze)
# Uploads (Cover, Interpretenbilder) bis 50 MB
ENV PHP_MEMORY_LIMIT=512M PHP_MAX_EXECUTION_TIME=120
RUN printf 'memory_limit = ${PHP_MEMORY_LIMIT}\nmax_execution_time = ${PHP_MAX_EXECUTION_TIME}\nupload_max_filesize = 50M\npost_max_size = 55M\n' > "$PHP_INI_DIR/conf.d/musicwiz.ini"

ENV SERVER_NAME=":8080"
WORKDIR /app
USER app
EXPOSE 8080

FROM base AS prod
COPY --chown=app:app . /app
COPY --from=assets --chown=app:app /app/public/build /app/public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && rm -rf demofiles
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/musicwiz-entrypoint
# Start als root: der Entrypoint übernimmt PUID/PGID (Besitzer des Shares) und läuft dann als dieser Benutzer weiter
USER root
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stack LOG_STACK=stderr,app_file LOG_DAILY_DAYS=7 \
    DB_CONNECTION=mariadb CACHE_STORE=file CACHE_FILE_PATH=/app/storage/app/cache \
    SESSION_DRIVER=file QUEUE_CONNECTION=database HOME=/tmp
ENTRYPOINT ["musicwiz-entrypoint"]
CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app/public"]
