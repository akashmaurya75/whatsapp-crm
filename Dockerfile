# Production image: PHP-FPM 8.3 with only the extensions this app needs.
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends libsodium-dev libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install pdo_mysql sodium mbstring \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { echo 'expose_php=Off'; echo 'session.cookie_httponly=1'; echo 'upload_max_filesize=2M'; echo 'post_max_size=2M'; } > "$PHP_INI_DIR/conf.d/zz-app.ini"

WORKDIR /var/www/whatsapp-crm
COPY --chown=www-data:www-data . .
RUN rm -f .env && mkdir -p storage/logs storage/sessions storage/mail \
    && chown -R www-data:www-data storage && chmod -R 0750 storage

USER www-data
EXPOSE 9000
CMD ["php-fpm"]
