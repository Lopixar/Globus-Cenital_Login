FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_sqlite curl \
    && rm -rf /var/lib/apt/lists/*

# Apache publica només la carpeta public; private queda fora del document root.
COPY public/ /var/www/html/
COPY private/ /var/www/private/

RUN chown -R www-data:www-data /var/www/html /var/www/private \
    && a2enmod headers

COPY docker-entrypoint.sh /usr/local/bin/globo-entrypoint
RUN chmod +x /usr/local/bin/globo-entrypoint

ENV PORT=10000
EXPOSE 10000
ENTRYPOINT ["globo-entrypoint"]
CMD ["apache2-foreground"]
