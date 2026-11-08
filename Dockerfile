FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

COPY . /var/www/html
COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html

EXPOSE 80
