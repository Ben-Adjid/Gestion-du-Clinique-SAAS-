# Build stage
FROM php:8.2-fpm as builder

WORKDIR /app

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libmcrypt-dev \
    git \
    curl \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --optimize-autoloader --no-dev

# Production stage
FROM php:8.2-fpm

WORKDIR /app

RUN apt-get update && apt-get install -y \
    libpq5 \
    mysql-client \
    supervisor \
    nginx

RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql

COPY --from=builder /app /app

COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/php.ini /usr/local/etc/php/php.ini
COPY ./docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN chown -R www-data:www-data /app

EXPOSE 80 443

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
