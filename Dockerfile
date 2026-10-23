FROM php:8.2-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    oniguruma-dev \
    linux-headers \
    bash \
    mysql-client

# Install PHP extensions for Laravel and Kafka
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    bcmath \
    sockets \
    pcntl \
    mbstring \
    zip

# Copy Composer binary
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy dependency files and install
COPY task/composer.json task/composer.lock ./
RUN composer install --no-interaction --no-scripts --no-progress --prefer-dist

# Copy application source
COPY task/ .
RUN composer dump-autoload --optimize
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
