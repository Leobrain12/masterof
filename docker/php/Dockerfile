FROM php:8.4-cli-alpine

# postgresql16-client (не generic postgresql-client) — Alpine's "latest" client package
# tracks the newest major (18 at time of writing), and pg_dump from a newer major emits
# settings the docker-compose db service (postgres:16-alpine) doesn't understand
# (e.g. "transaction_timeout"), silently breaking db:restore. Bump this alongside the
# db service's image tag in docker-compose.yml, never independently.
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
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
