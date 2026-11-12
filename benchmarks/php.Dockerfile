FROM php:8.4-fpm
RUN apt-get update && apt-get install -y git unzip libpq-dev libzip-dev && docker-php-ext-install pdo_pgsql pgsql zip bcmath opcache && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY src/ ./
RUN test -f vendor/autoload.php && mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && chown -R www-data:www-data bootstrap/cache storage
RUN echo "pm.status_path = /fpm-status" > /usr/local/etc/php-fpm.d/zz-benchmark-status.conf
CMD ["php-fpm"]
