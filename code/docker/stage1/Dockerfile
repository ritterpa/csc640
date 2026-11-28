# Stage 1 (raw PHP) API image for the Docker Compose stack (docker-compose.yml).
#
# Stage 1 has no dependencies to install: only PHP with pdo_mysql. Its code
# (code/stage1) is mounted read-only at /app, and PHP's built-in server reads
# each file per request, so code changes need no restart or rebuild.
# Settings come from environment variables, which Stage 1's Config prefers
# over a .env file (and the built-in server, unlike "php artisan serve",
# passes them through).
FROM php:8.5-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
EXPOSE 8180

# Same routing as NGINX's try_files: public/index.php serves the web pages
# itself under the built-in server and sends everything else to the API.
CMD ["php", "-S", "0.0.0.0:8180", "-t", "public", "public/index.php"]
