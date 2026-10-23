FROM php:8.2-cli-alpine

# Install system dependencies & build tools
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

# Install PHP extensions required by Laravel & Kafka client
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

# Set working directory
WORKDIR /var/www/html

# Copy dependency definitions first for Docker layer caching
COPY composer.json composer.lock ./

# Install Composer dependencies
RUN composer install --no-interaction --no-scripts --no-progress --prefer-dist

# Copy the rest of the application code
COPY . .

# Generate autoload files & optimize
RUN composer dump-autoload --optimize

# Ensure storage and cache directories have write permissions
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
