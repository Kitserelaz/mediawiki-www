# syntax=docker/dockerfile:1.4
# Immutable multi-platform base digests and a dated Debian snapshot fix dependency resolution.
ARG PHP_IMAGE=php:8.4.28-fpm-bookworm@sha256:e4b1eec29a5be22428aa93fb43da75a0e39006e403da0c3e93631dde92f067df
ARG CADDY_IMAGE=caddy:2.11.9-alpine@sha256:003a97808e781aa9f7e75cc2f43d34d461099031faf5e20faefb9f61126836f0
FROM ${PHP_IMAGE} AS php
ARG DEBIAN_SNAPSHOT=20261119T000000Z
RUN printf 'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/%s/ bookworm main\ndeb [check-valid-until=no] https://snapshot.debian.org/archive/debian/%s/ bookworm-updates main\ndeb [check-valid-until=no] https://snapshot.debian.org/archive/debian-security/%s/ bookworm-security main\n' "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" > /etc/apt/sources.list \
    && rm -f /etc/apt/sources.list.d/debian.sources
RUN set -eux; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update && apt-get upgrade -y --no-install-recommends -o Dpkg::Options::="--force-confold" \
    && apt-get install -y --no-install-recommends \
    imagemagick ffmpeg lua5.1 zip unzip diffutils fonts-freefont-ttf \
    librsvg2-bin ghostscript graphviz ploticus curl ca-certificates mariadb-client tini \
    kakasi poppler-utils djvulibre-bin libtiff-tools python3 python3-pygments \
    python3-aiohttp python3-redis p7zip-full zstd \
    && runtimeAptMark="$(apt-mark showmanual)" \
    && apt-get install -y --no-install-recommends \
    libicu-dev libxml2-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libgmp-dev libldap2-dev libmemcached-dev zlib1g-dev libssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    # mbstring and OPcache are already enabled in the pinned PHP base.
    && docker-php-ext-install -j"$(nproc)" intl mysqli pdo_mysql gd zip gmp bcmath ldap exif calendar pcntl sockets \
    && pecl install redis-6.2.0 memcached-3.3.0 apcu-5.1.28 \
    && docker-php-ext-enable redis memcached apcu \
    # ResourceLoader caches minified assets in CACHE_ACCEL, independently of Memcached.
    && printf 'apc.shm_size=128M\n' > /usr/local/etc/php/conf.d/farm-apcu.ini \
    # Keep runtime tools and the shared libraries used by PHP and its extensions.
    && apt-mark auto '.*' > /dev/null \
    && apt-mark manual $runtimeAptMark > /dev/null \
    && apt-mark auto $PHPIZE_DEPS > /dev/null \
    && find /usr/local -type f \( -executable -o -name '*.so' \) -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | awk 'sub(":$", "", $1) { print $1 }' \
        | sort -u \
        | xargs -r apt-mark manual \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
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
COPY --link docker/mediawiki/custom-domain-fpm.conf /usr/local/etc/php-fpm.d/
COPY --link docker/mediawiki/private.php ./PrivateSettings.php
RUN mkdir -p docker images cw_cache cache /var/www/dumps /var/log/mediawiki \
    && ln -s /opt/farm docker/mediawiki \
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
