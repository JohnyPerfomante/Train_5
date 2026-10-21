FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf
COPY . /var/www/html
RUN printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nsession.use_strict_mode=1\n' > /usr/local/etc/php/conf.d/app.ini
