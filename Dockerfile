FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libjpeg62-turbo-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Multipart transport needs modest headroom above the application's exact
# 5 MiB per-image validation limit.
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-dryherbarium-uploads.ini

WORKDIR /var/www

CMD ["php-fpm"]
