#!/bin/sh
set -e

# Other containers on the same data volume (e.g. the scheduler) reuse the generated secret.
if [ -z "$APP_SECRET" ] && [ -s "${APP_DATA_DIR:-/app/var/data}/app_secret" ]; then
    APP_SECRET="$(cat "${APP_DATA_DIR:-/app/var/data}/app_secret")"
    export APP_SECRET
fi

# Runs only for the web server command (and explicit "app-init"); other commands
# (php bin/phpunit, composer ...) are executed as is.
if [ "$1" = 'frankenphp' ] || [ "$1" = 'app-init' ]; then
    mkdir -p "$APP_DATA_DIR"

    if [ "$APP_ENV" != 'prod' ] && [ ! -f vendor/autoload_runtime.php ]; then
        composer install --prefer-dist --no-progress --no-interaction
    fi

    # APP_SECRET is never committed. If it is not supplied via the environment,
    # generate it once and keep it on the persistent data volume.
    if [ -z "$APP_SECRET" ] && [ ! -s "$APP_DATA_DIR/app_secret" ]; then
        php -r 'echo bin2hex(random_bytes(32));' > "$APP_DATA_DIR/app_secret"
        chmod 600 "$APP_DATA_DIR/app_secret"
    fi

    if [ -z "$APP_SECRET" ]; then
        APP_SECRET="$(cat "$APP_DATA_DIR/app_secret")"
        export APP_SECRET
    fi

    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing

    if [ "$1" = 'app-init' ]; then
        exit 0
    fi
fi

exec docker-php-entrypoint "$@"
