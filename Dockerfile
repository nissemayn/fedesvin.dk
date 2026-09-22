FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && mkdir -p /data \
    && chown www-data:www-data /data

WORKDIR /var/www/html
COPY index.php style.css details.css app.js ./

RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '  Options -Indexes' \
    '  AllowOverride None' \
    '  Require all granted' \
    '  RewriteEngine On' \
    '  RewriteCond %{REQUEST_FILENAME} !-f' \
    '  RewriteRule ^ index.php [QSA,L]' \
    '</Directory>' \
    > /etc/apache2/conf-available/fedesvin.conf \
    && a2enconf fedesvin \
    && printf '%s\n' 'expose_php=Off' 'session.use_strict_mode=1' > /usr/local/etc/php/conf.d/fedesvin.ini

EXPOSE 80
