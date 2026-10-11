# Serves the whole project: landing page at /, PHP app at /session-system/
FROM php:8.3-apache

# pdo_mysql: the app stores its data in the HostForge MariaDB database (DB_* variables); .htaccess support for the deny rules
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY . /var/www/html/

# Only used when SSMS_STORAGE=file (no database configured): the JSON data folder must be writable by Apache
RUN mkdir -p /var/www/html/session-system/data/backups \
 && chown -R www-data:www-data /var/www/html/session-system/data

EXPOSE 80
