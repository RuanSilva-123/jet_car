# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base: PHP-FPM + extensões usadas pelo Laravel (app, queue e scheduler)
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-libs libpq libzip fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# dev: código montado via volume, opcache revalida a cada request
# ---------------------------------------------------------------------------
FROM base AS dev

COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

# ---------------------------------------------------------------------------
# prod: código copiado para a imagem, dependências sem dev, caches gerados
# ---------------------------------------------------------------------------
FROM base AS prod

COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
