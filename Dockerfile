FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y git unzip libzip-dev libonig-dev libxml2-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring zip gd \
    && a2enmod rewrite

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
