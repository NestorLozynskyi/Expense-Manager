# ---- base: PHP 8.5 + FrankenPHP (Caddy) --------------------------------------
FROM dunglas/frankenphp:1-php8.5 AS base

WORKDIR /app

RUN install-php-extensions @composer intl zip opcache pdo_sqlite

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=":80" \
    APP_DATA_DIR=/app/var/data

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod 755 /usr/local/bin/app-entrypoint

EXPOSE 80
HEALTHCHECK --interval=5s --timeout=3s --start-period=20s --retries=10 \
    CMD curl -fsS http://127.0.0.1:80/healthz || exit 1

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---- dev: source code is bind-mounted, dev dependencies available ------------
FROM base AS dev
ENV APP_ENV=dev

# ---- prod: self-contained image used by `docker compose up` ------------------
FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . .
RUN set -eux; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd; \
    php bin/console importmap:install; \
    php bin/console tailwind:build --minify; \
    php bin/console asset-map:compile; \
    php bin/console cache:warmup; \
    mkdir -p var/data; \
    chmod -R a+rwX var
