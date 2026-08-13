# syntax=docker/dockerfile:1.7@sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e

FROM composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer validate --no-interaction --strict \
    && composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && rm -f vendor/endroid/qr-code/assets/noto_sans.otf \
    && test ! -e vendor/endroid/qr-code/assets/noto_sans.otf

FROM node:20-alpine@sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293 AS frontend-build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

COPY tailwind.config.js ./
COPY static/css/input.css ./static/css/input.css
COPY static/js ./static/js
COPY module ./module
COPY tpl ./tpl
COPY rw.php ./
RUN npm run css:build

# Keep the distributable application on an explicit allowlist. This prevents
# CI metadata, tests, local tools and future repository files from silently
# entering the runtime image or the shared-hosting archive.
FROM alpine:3.22@sha256:14358309a308569c32bdc37e2e0e9694be33a9d99e68afb0f5ff33cc1f695dce AS application-files

WORKDIR /opt/hscript
COPY .htaccess 404.html _dbstru.php favicon.ico favicon.svg rw.php ./
COPY _a-ddos ./_a-ddos
COPY bin ./bin
COPY lang ./lang
COPY lib ./lib
COPY migrations ./migrations
COPY module ./module
COPY src ./src
COPY static ./static
COPY tpl ./tpl
COPY logs/.htaccess ./logs/.htaccess
COPY tpl_c/.htaccess ./tpl_c/.htaccess
COPY upload/.htaccess ./upload/.htaccess

RUN rm -f static/css/input.css \
    && mkdir -p backup compile .cfg \
    && chmod 0750 backup compile logs tpl_c upload .cfg

FROM alpine:3.22@sha256:14358309a308569c32bdc37e2e0e9694be33a9d99e68afb0f5ff33cc1f695dce AS shared-hosting-files

ARG APP_VERSION=1.0.0
ARG VCS_REF=unknown
ARG BUILD_DATE=unknown

WORKDIR /release/h-script
COPY --from=application-files /opt/hscript ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend-build /app/static/css/app.css ./static/css/app.css
COPY README.md CHANGELOG.md LICENSE composer.json composer.lock ./
COPY docs ./docs

RUN printf '%s\n' \
        "H-Script ${APP_VERSION}" \
        "Revision: ${VCS_REF}" \
        "Built: ${BUILD_DATE}" \
        > RELEASE \
    && chmod 0644 RELEASE

FROM scratch AS shared-hosting
COPY --from=shared-hosting-files /release/h-script /h-script

# The archive is assembled in a pinned GNU userspace so ordering, ownership and
# timestamps are normalized. The final target contains only release artifacts.
FROM debian:bookworm-slim@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241 AS shared-archive

ARG APP_VERSION=1.0.0
ARG SOURCE_DATE_EPOCH=0

WORKDIR /work
COPY --from=shared-hosting /h-script ./h-script

RUN case "${APP_VERSION}" in \
        *[!0-9A-Za-z._-]*|'') echo "Invalid APP_VERSION" >&2; exit 1 ;; \
    esac \
    && case "${SOURCE_DATE_EPOCH}" in \
        *[!0-9]*|'') echo "Invalid SOURCE_DATE_EPOCH" >&2; exit 1 ;; \
    esac \
    && find h-script -exec touch --date="@${SOURCE_DATE_EPOCH}" {} + \
    && mkdir -p /out \
    && tar \
        --sort=name \
        --mtime="@${SOURCE_DATE_EPOCH}" \
        --clamp-mtime \
        --owner=0 \
        --group=0 \
        --numeric-owner \
        --format=posix \
        -czf "/out/h-script-${APP_VERSION}-shared-hosting.tar.gz" \
        h-script \
    && cd /out \
    && sha256sum "h-script-${APP_VERSION}-shared-hosting.tar.gz" > SHA256SUMS

FROM scratch AS shared-release
COPY --from=shared-archive /out /

FROM php:8.4-fpm-alpine@sha256:5992f8b7433fe7fa96dfbf67746c86d6c41bc91e686eac38fe531c72a02e40e4 AS runtime

WORKDIR /var/www/html

RUN apk add --no-cache \
        apache2 \
        apache2-proxy \
        ca-certificates \
        curl \
        freetype \
        libjpeg-turbo \
        libpng \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        pdo_mysql \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && php -r '$required = ["curl", "dom", "gd", "mbstring", "openssl", "pdo_mysql", "redis", "simplexml", "sodium", "Zend OPcache"]; foreach ($required as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\\n"); exit(1); } }' \
    && apk del .build-deps \
    && rm -rf /tmp/pear /usr/src/php.tar.xz /usr/src/php.tar.xz.asc \
    && mkdir -p /run/apache2 /var/log/apache2

FROM runtime AS app

ARG APP_VERSION=1.0.0
ARG VCS_REF=unknown
ARG BUILD_DATE=unknown

COPY --from=application-files /opt/hscript ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend-build /app/static/css/app.css ./static/css/app.css
COPY docker/apache/httpd.conf /etc/apache2/httpd.conf
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/90-hscript.ini
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint-hscript
COPY docker/runtime/cron.sh /usr/local/bin/hscript-cron
COPY docker/runtime/write-config.php /usr/local/share/hscript/write-config.php
COPY docker/runtime/install-db.php /usr/local/share/hscript/install-db.php

RUN chmod +x /usr/local/bin/docker-entrypoint-hscript /usr/local/bin/hscript-cron \
    && chown -R www-data:www-data logs tpl_c upload compile backup .cfg

LABEL org.opencontainers.image.title="H-Script" \
      org.opencontainers.image.source="https://github.com/0x241/h-script" \
      org.opencontainers.image.description="H-Script application runtime" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.created="${BUILD_DATE}"

EXPOSE 80

ENTRYPOINT ["docker-entrypoint-hscript"]
CMD ["httpd", "-DFOREGROUND", "-f", "/etc/apache2/httpd.conf"]
