FROM php:8.2-apache

RUN docker-php-ext-install mysqli

# Allow multi-image product uploads without PHP dropping the whole POST body
RUN printf "upload_max_filesize=10M\npost_max_size=40M\nmax_file_uploads=20\nmemory_limit=256M\n" \
    > /usr/local/etc/php/conf.d/flixo-uploads.ini

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads/products \
             /var/www/html/uploads/kyc \
             /var/www/html/uploads/slips \
             /var/www/html/uploads/disputes \
    && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80
