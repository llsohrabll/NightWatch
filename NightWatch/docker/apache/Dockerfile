FROM php:8.3-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libzip-dev mariadb-client \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install mysqli gd \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/NightWatch
