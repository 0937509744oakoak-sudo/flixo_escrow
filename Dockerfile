FROM php:8.2-apache

RUN docker-php-ext-install mysqli

# Allow multi-image product uploads without PHP dropping the whole POST body
RUN printf "upload_max_filesize=12M\npost_max_size=64M\nmax_file_uploads=20\nmemory_limit=256M\n" \
    > /usr/local/etc/php/conf.d/flixo-uploads.ini

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads/products \
             /var/www/html/uploads/kyc \
             /var/www/html/uploads/slips \
             /var/www/html/uploads/disputes \
             /var/www/html/uploads/sessions \
    && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80

# Railway Volume mounts at runtime over /var/www/html/uploads — recreate subdirs + fix perms.
# Prefer RAILWAY_RUN_UID=0 in Railway Variables so www-data can write the volume.
CMD ["bash", "-lc", "UPLOAD_ROOT=\"${RAILWAY_VOLUME_MOUNT_PATH:-/var/www/html/uploads}\"; mkdir -p \"$UPLOAD_ROOT/products\" \"$UPLOAD_ROOT/kyc\" \"$UPLOAD_ROOT/slips\" \"$UPLOAD_ROOT/disputes\" \"$UPLOAD_ROOT/sessions\"; chown -R www-data:www-data \"$UPLOAD_ROOT\" || true; chmod -R ug+rwX \"$UPLOAD_ROOT\" || true; date > \"$UPLOAD_ROOT/.volume_ok\" || true; chown www-data:www-data \"$UPLOAD_ROOT/.volume_ok\" || true; a2dismod mpm_event mpm_worker || true; rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* || true; a2enmod mpm_prefork; exec apache2-foreground"]
