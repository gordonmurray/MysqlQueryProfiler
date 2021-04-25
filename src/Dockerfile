FROM php:7.4-apache

RUN docker-php-ext-install mysqli

COPY index.php /var/www/html

EXPOSE 80

RUN usermod -u 1000 www-data; \
    chown -R www-data:www-data /var/www/html