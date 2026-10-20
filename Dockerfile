FROM php:8.2-apache

# mbstring is required by dashboard chat helpers (mb_substr/mb_strlen).
# Without it, production fatals mid-render → White Screen of Death.
# fileinfo/gd/exif used for uploads and image checks.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli mbstring fileinfo gd exif \
    && rm -rf /var/lib/apt/lists/*

# Allow multi-image product uploads without PHP dropping the whole POST body
RUN printf "upload_max_filesize=12M\npost_max_size=64M\nmax_file_uploads=20\nmemory_limit=256M\ndisplay_errors=Off\nlog_errors=On\n" \
    > /usr/local/etc/php/conf.d/flixo-uploads.ini

# Hardening must be enabled before the app is served. Kept at server level (not
# only .htaccess) because Railway mounts a volume over uploads/ at runtime,
# which would otherwise wipe the .htaccess files guarding private uploads.
COPY docker/flixo-security.conf /etc/apache2/conf-available/flixo-security.conf
RUN a2enconf flixo-security

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads/products \
             /var/www/html/uploads/kyc \
             /var/www/html/uploads/slips \
             /var/www/html/uploads/disputes \
             /var/www/html/uploads/sessions \
             /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs

EXPOSE 80

# Railway Volume mounts at runtime over /var/www/html/uploads — recreate subdirs + fix perms.
# Prefer RAILWAY_RUN_UID=0 in Railway Variables so www-data can write the volume.
CMD ["bash", "-lc", "UPLOAD_ROOT=\"${RAILWAY_VOLUME_MOUNT_PATH:-/var/www/html/uploads}\"; mkdir -p \"$UPLOAD_ROOT/products\" \"$UPLOAD_ROOT/kyc\" \"$UPLOAD_ROOT/slips\" \"$UPLOAD_ROOT/disputes\" \"$UPLOAD_ROOT/sessions\" /var/www/html/logs; chown -R www-data:www-data \"$UPLOAD_ROOT\" /var/www/html/logs || true; chmod -R ug+rwX \"$UPLOAD_ROOT\" /var/www/html/logs || true; date > \"$UPLOAD_ROOT/.volume_ok\" || true; chown www-data:www-data \"$UPLOAD_ROOT/.volume_ok\" || true; a2dismod mpm_event mpm_worker || true; rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* || true; a2enmod mpm_prefork; exec apache2-foreground"]
