# Etapa base: PHP + extensiones que Laravel necesita para Postgres, Mongo y Redis
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    linux-headers \
    autoconf \
    g++ \
    make \
    git \
    unzip \
    $PHPIZE_DEPS

RUN docker-php-ext-install pdo pdo_pgsql pgsql zip intl mbstring bcmath

RUN pecl install mongodb redis \
    && docker-php-ext-enable mongodb redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Etapa de desarrollo: agrega Xdebug para poder depurar paso a paso
FROM base AS development

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

CMD ["php-fpm"]

# Etapa de producción: sin Xdebug, dependencias ya instaladas y optimizadas
FROM base AS production

COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

CMD ["php-fpm"]
