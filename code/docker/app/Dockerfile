# Stage 2 (Laravel) API image for the Docker Compose stack (docker-compose.yml).
#
# Same steps as code/stage2/run.sh, done once at build time: create the
# Laravel project and add firebase/php-jwt. The project's own files
# (code/stage2/src) are NOT baked in: they are mounted at /src and copied over
# the project each time the container starts (entrypoint.sh), so a restart
# picks up code changes without a rebuild.
FROM php:8.5-cli

# pdo_mysql for Eloquent; git and unzip let Composer download packages.
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && docker-php-ext-install pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Same versions as run.sh.
ARG LARAVEL_VERSION=^13.0
RUN composer create-project laravel/laravel /app "$LARAVEL_VERSION" --no-interaction --prefer-dist \
 && cd /app \
 && composer require firebase/php-jwt:^7.1 --no-interaction

WORKDIR /app
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

EXPOSE 8181
ENTRYPOINT ["sh", "/usr/local/bin/entrypoint.sh"]
