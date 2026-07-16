web: bash -c "a2dismod mpm_event mpm_worker || true; a2enmod mpm_prefork; sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf; exec apache2-foreground"
