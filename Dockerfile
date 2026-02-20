FROM php:8.3-apache

# Instalar dependencias del sistema y extensiones PHP (igual que producción)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libsodium-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        mysqli \
        mbstring \
        zip \
        gd \
        curl \
        sodium \
        opcache \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Aumentar límites de PHP para PhpSpreadsheet y archivos grandes
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/custom.ini \
    && echo "upload_max_filesize=64M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size=64M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html

COPY . /var/www/html

# Set document root to public
RUN sed -i 's#/var/www/html#/var/www/html/public#' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's#/var/www/html#/var/www/html/public#' /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN git config --global --add safe.directory /var/www/html \
    && composer install --no-dev --optimize-autoloader

# Set permissions for Laravel writable dirs
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data

EXPOSE 80

CMD ["apache2-foreground"]
