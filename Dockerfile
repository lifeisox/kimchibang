FROM php:8.5-cli
RUN docker-php-ext-install pdo_mysql
COPY config/php-development.ini /usr/local/etc/php/conf.d/kimchibang.ini
WORKDIR /app
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
