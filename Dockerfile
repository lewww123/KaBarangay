FROM php:8.2-apache

# Mag-install ng system dependencies at PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libpq-dev \
    zip \
    unzip \
    git \
    curl

RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install pdo pdo_pgsql gd

# I-install ang Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Mag-set ng working directory
WORKDIR /var/www/html

# Kopyahin ang project files
COPY . .

# I-install ang PHP dependencies nang walang dev packages para sa production
RUN composer install --no-dev --optimize-autoloader

# I-set ang Apache document root patungo sa Laravel public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -s 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -s 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

RUN a2enmod rewrite

# Baguhin ang permissions para sa storage at cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80
