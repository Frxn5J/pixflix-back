# Production image for Coolify (web, queue and scheduler use the same image).
# Keep the runtime image small and compile all PHP extensions in a throwaway
# build stage so the application starts consistently on every deployment.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --no-scripts --optimize-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.2-fpm-alpine AS runtime
WORKDIR /var/www/html

RUN apk add --no-cache \
        curl \
        icu-libs \
        libzip \
        libxml2 \
        nginx \
        oniguruma \
        postgresql-libs \
        supervisor \
        wget \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        curl-dev \
        icu-dev \
        libxml2-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" \
        bcmath \
        curl \
        intl \
        mbstring \
        opcache \
        pdo_pgsql \
        xml \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-pixflix.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/pixflix-entrypoint

RUN chmod +x /usr/local/bin/pixflix-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache /run/nginx \
    && chown -R www-data:www-data storage bootstrap/cache /run/nginx

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -q -O - http://127.0.0.1:8080/up >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/pixflix-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
