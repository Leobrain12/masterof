FROM php:8.4-cli-alpine

# postgresql16-client (не generic postgresql-client) — Alpine's "latest" client package
# tracks the newest major (18 at time of writing), and pg_dump from a newer major emits
# settings the docker-compose db service (postgres:16-alpine) doesn't understand
# (e.g. "transaction_timeout"), silently breaking db:restore. Bump this alongside the
# db service's image tag in docker-compose.yml, never independently.
# Redis — через predis (чистый PHP-клиент, composer), не через PECL-расширение
# phpredis: сборка PECL требует сети до pecl.php.net на этапе docker build,
# а это не всегда доступно (см. vault/Решения.md — реальный сбой на билд-сервере
# Dokploy). predis чуть медленнее phpredis, но для этого масштаба разница не важна,
# а независимость от pecl.php.net на билде — важна.
RUN apk add --no-cache \
        postgresql-dev \
        postgresql16-client \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        git \
        unzip \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql mbstring bcmath intl zip \
    && apk del $PHPIZE_DEPS

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
