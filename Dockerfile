FROM php:8.5-cli

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y git unzip libpq-dev libzip-dev && docker-php-ext-install pdo pdo_pgsql pgsql zip sockets && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

RUN php artisan package:discover --ansi

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
