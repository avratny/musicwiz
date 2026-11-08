# MusicWiz – PHP (FrankenPHP) + Tag-Tool (Python/mutagen)
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
    && apt-get install -y --no-install-recommends python3 python3-mutagen unzip git ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_sqlite gd intl zip pcntl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# YouTube-Download: yt-dlp (braucht für YouTube eine JS-Laufzeit, daher Deno)
COPY --from=denoland/deno:bin /deno /usr/local/bin/deno
RUN curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp \
    && chmod 755 /usr/local/bin/yt-dlp

# Container läuft mit UID/GID des Shares, damit neue Dateien nicht root gehören
RUN groupadd -o -g ${PGID} app && useradd -o -u ${PUID} -g ${PGID} -m app \
    && setcap -r /usr/local/bin/frankenphp || true \
    && chown -R app:app /data /config /app /usr/local/bin/yt-dlp

# Speichergrenze je PHP-Prozess (Aufräumen über die ganze Lib braucht bei ~15.000 Titeln gut 200 MB); per .env änderbar
ENV PHP_MEMORY_LIMIT=512M
RUN echo 'memory_limit = ${PHP_MEMORY_LIMIT}' > "$PHP_INI_DIR/conf.d/musicwiz.ini"

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
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr \
    DB_DATABASE=/app/storage/app/musicwiz.sqlite CACHE_STORE=file CACHE_FILE_PATH=/app/storage/app/cache \
    SESSION_DRIVER=file QUEUE_CONNECTION=database HOME=/tmp
ENTRYPOINT ["musicwiz-entrypoint"]
CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app/public"]
