# syntax=docker/dockerfile:1.7
#
# Aloha Social standalone server image.
#
# The committed UI bundle in public/ui/ is copied as-is, so the runtime image
# never needs Node.js. Composer runs in a separate stage and only its vendor/
# directory and the generated autoloader reach the final image.

FROM composer:2 AS vendor

WORKDIR /app

# The core package is a VCS dependency, so the build needs network access to
# GitHub. The lock file pins the exact revision that was tested.
COPY composer.json composer.lock ./
# The composer image has no gd; the runtime stage installs it. The platform
# check is therefore repeated in the runtime stage with a real request.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
        --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction


FROM php:8.3-apache AS runtime

# pdo_sqlite ships with the official image; PostgreSQL and GD need the
# matching headers. Both database drivers are installed so the same image can
# run the SQLite default and the PostgreSQL compose profile.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_pgsql opcache \
    && a2enmod expires headers rewrite proxy proxy_wstunnel \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-aloha.ini
COPY docker/entrypoint.sh /usr/local/bin/aloha-entrypoint

RUN chmod 0755 /usr/local/bin/aloha-entrypoint \
    && mkdir -p var/blobs var/mail \
    && chown -R www-data:www-data var

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=5 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1/healthz') === false ? 1 : 0);"

ENTRYPOINT ["/usr/local/bin/aloha-entrypoint"]
CMD ["apache2-foreground"]
