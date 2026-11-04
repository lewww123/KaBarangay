FROM php:8.3-apache AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

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

RUN apt-get update && apt-get install -y supervisor

RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install pdo pdo_pgsql gd zip intl xml bcmath mbstring opcache
COPY docker/php-production.ini /usr/local/etc/php/conf.d/production.ini

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
RUN a2enmod rewrite headers deflate
COPY docker/performance.conf /etc/apache2/conf-available/performance.conf
RUN a2enconf performance
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

# Build React assets with PHP available for the Wayfinder route generator.
FROM node:22-bookworm-slim AS node

FROM app AS frontend
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN node /usr/local/lib/node_modules/npm/bin/npm-cli.js ci --include=dev
RUN node /usr/local/lib/node_modules/npm/bin/npm-cli.js run build

FROM app AS production
COPY --from=frontend /var/www/html/public/build /var/www/html/public/build
COPY docker/resident-updates-supervisor.conf /etc/supervisor/conf.d/resident-updates.conf

EXPOSE 80

# Cache only at startup, after Render supplies the actual environment values.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan view:cache && chown -R www-data:www-data bootstrap/cache storage/framework/views && exec supervisord -n -c /etc/supervisor/conf.d/resident-updates.conf"]
