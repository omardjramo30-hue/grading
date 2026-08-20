FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite \
    && a2dismod --force mpm_event mpm_worker \
    && a2enmod mpm_prefork rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && apache2ctl configtest \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data

EXPOSE 80

# Railway injects PORT at runtime. Reassert a single PHP-compatible Apache MPM
# immediately before startup, then bind Apache to Railway's runtime port.
CMD ["sh", "-c", "rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf && a2enmod mpm_prefork >/dev/null && sed -ri \"s/^Listen [0-9]+$/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -ri \"s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && apache2ctl configtest && exec apache2-foreground"]
