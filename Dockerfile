FROM php:8.2-apache

RUN docker-php-ext-install mysqli

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads/products \
             /var/www/html/uploads/kyc \
             /var/www/html/uploads/slips \
             /var/www/html/uploads/disputes \
    && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80
