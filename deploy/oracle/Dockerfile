FROM node:24-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json vite.config.js ./
RUN npm ci
COPY resources ./resources
RUN npm run build

FROM php:8.4-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libonig-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql mbstring gd zip pcntl bcmath opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY lang ./lang
COPY resources ./resources
COPY routes ./routes
COPY public ./public
COPY artisan ./artisan
COPY --from=assets /build/public/build ./public/build
COPY deploy/oracle/php.ini /usr/local/etc/php/conf.d/revilo.ini
COPY deploy/oracle/entrypoint.sh /usr/local/bin/revilo-entrypoint
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && ln -s ../storage/app/public public/storage \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chmod 755 /usr/local/bin/revilo-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["revilo-entrypoint"]
CMD ["php-fpm"]

FROM caddy:2-alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY deploy/oracle/Caddyfile /etc/caddy/Caddyfile
