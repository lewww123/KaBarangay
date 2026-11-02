FROM php:8.3-apache

# Mag-install ng system dependencies at PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libonig-dev \
    zip \
    unzip \
    git \
    curl

RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install pdo pdo_pgsql gd zip intl xml bcmath mbstring

# I-install ang Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Mag-set ng working directory
WORKDIR /var/www/html

# Kopyahin muna ang composer files
COPY composer.json composer.lock ./

# I-install ang dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Kopyahin ang buong project files
COPY . .

# I-run ang laravel package discovery
RUN composer dump-autoload --optimize

# I-enable ang Apache rewrite at i-configure ang AllowOverride para sa Laravel
RUN a2enmod rewrite
RUN echo '<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/laravel.conf && a2enconf laravel

# I-set ang Apache document root patungo sa Laravel public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -s 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -s 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Baguhin ang permissions para sa storage at cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80
