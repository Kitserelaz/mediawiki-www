# syntax=docker/dockerfile:1.4
# Immutable multi-platform base digests and a dated Debian snapshot fix dependency resolution.
ARG PHP_IMAGE=php:8.4.27-fpm-bookworm@sha256:df5a5113e1179ddf4560cd15727eb3812f6457829756d2a96c6c11bb4c250089
ARG CADDY_IMAGE=caddy:2.10.2-alpine@sha256:4c6e91c6ed0e2fa03efd5b44747b625fec79bc9cd06ac5235a779726618e530d
FROM ${PHP_IMAGE} AS php
ARG DEBIAN_SNAPSHOT=20261015T000000Z
RUN printf 'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/%s/ bookworm main\ndeb [check-valid-until=no] https://snapshot.debian.org/archive/debian/%s/ bookworm-updates main\ndeb [check-valid-until=no] https://snapshot.debian.org/archive/debian-security/%s/ bookworm-security main\n' "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" > /etc/apt/sources.list \
    && rm -f /etc/apt/sources.list.d/debian.sources
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libonig-dev libxml2-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libgmp-dev libldap2-dev libmemcached-dev zlib1g-dev libssl-dev \
    imagemagick ffmpeg lua5.1 zip unzip diffutils fonts-freefont-ttf \
    librsvg2-bin ghostscript graphviz ploticus curl ca-certificates mariadb-client tini \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl mbstring mysqli pdo_mysql gd zip gmp bcmath ldap exif calendar pcntl sockets opcache \
    && pecl install redis-6.2.0 memcached-3.3.0 \
    && docker-php-ext-enable redis memcached \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
RUN apt-get update && apt-get install -y --no-install-recommends poppler-utils djvulibre-bin libtiff-tools python3 python3-pygments && rm -rf /var/lib/apt/lists/*
# ResourceLoader caches minified assets in CACHE_ACCEL, independently of Memcached.
RUN pecl install apcu-5.1.28 \
    && docker-php-ext-enable apcu \
    && printf 'apc.shm_size=128M\n' > /usr/local/etc/php/conf.d/farm-apcu.ini \
    && rm -rf /tmp/pear
RUN apt-get update && apt-get install -y --no-install-recommends p7zip-full zstd \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/mediawiki
# Keep large dependency trees separate from frequently edited farm settings.
# Linked source layers are reusable across architectures and PHP dependency updates.
COPY --link mediawiki/vendor/ ./vendor/
COPY --link mediawiki/extensions/ ./extensions/
COPY --link mediawiki/skins/ ./skins/
COPY --link mediawiki/resources/ ./resources/
COPY --link mediawiki/languages/ ./languages/
COPY --link mediawiki/includes/ ./includes/
COPY --link mediawiki/maintenance/ ./maintenance/
COPY --link mediawiki/sql/ ./sql/
COPY --link mediawiki/ErrorPages/ ./ErrorPages/
COPY --link mediawiki/docs/ ./docs/
COPY --link mediawiki/*.php ./
COPY --link mediawiki/composer.json mediawiki/composer.local.json mediawiki/composer.lock mediawiki/README.md mediawiki/INSTALL mediawiki/UPGRADE mediawiki/FAQ mediawiki/COPYING mediawiki/CREDITS mediawiki/HISTORY mediawiki/SECURITY mediawiki/RELEASE-NOTES-1.46 ./
COPY --link docker/mediawiki/ /opt/farm/
COPY --link docker/mediawiki/ ./docker/mediawiki/
COPY --link docker/mediawiki/private.php ./PrivateSettings.php
RUN mkdir -p images cw_cache cache /var/www/dumps /var/log/mediawiki \
    && chown -R www-data:www-data images cw_cache cache /var/www/dumps /var/log/mediawiki \
    && chmod +x /opt/farm/entrypoint.sh \
    && printf 'upload_max_filesize=100M\npost_max_size=110M\nmemory_limit=512M\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/farm.ini
ENV MW_DOCKER=1 MW_INSTALL_PATH=/var/www/mediawiki
ARG SOURCE_REVISION=unknown
LABEL org.opencontainers.image.source="https://github.com/Kitserelaz/mediawiki-www" \
      org.opencontainers.image.revision="${SOURCE_REVISION}"
ENTRYPOINT ["tini", "--", "/opt/farm/entrypoint.sh"]
CMD ["php-fpm"]
FROM php AS assets
RUN python3 /opt/farm/export-assets.py
FROM ${CADDY_IMAGE} AS web
COPY --link --from=assets /opt/public/ /var/www/mediawiki/
COPY --link --from=php /var/www/mediawiki/index.php /var/www/mediawiki/api.php /var/www/mediawiki/load.php /var/www/mediawiki/rest.php /var/www/mediawiki/img_auth.php /var/www/mediawiki/thumb.php /var/www/mediawiki/thumb_handler.php /var/www/mediawiki/opensearch_desc.php /var/www/mediawiki/
ARG SOURCE_REVISION=unknown
LABEL org.opencontainers.image.source="https://github.com/Kitserelaz/mediawiki-www" \
      org.opencontainers.image.revision="${SOURCE_REVISION}"
