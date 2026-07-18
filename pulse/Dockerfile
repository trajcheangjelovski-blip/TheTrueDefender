# The Daily Pulse — PHP-FPM runtime image (app, queue worker, scheduler share this)
FROM php:8.3-fpm-bookworm

# System libraries + PHP extensions the app relies on:
#  pdo_pgsql/pgsql -> PostgreSQL   gmp -> web-push VAPID crypto   redis -> cache/queue/session
#  gd/intl/zip/exif/bcmath -> Filament + images + i18n   pcntl -> queue worker signals   opcache -> perf
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip ca-certificates \
        libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu-dev libgmp-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql gd zip intl gmp bcmath exif pcntl mbstring opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
