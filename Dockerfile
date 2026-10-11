# Serves the whole project: landing page at /, PHP app at /session-system/
FROM php:8.3-apache

# .htaccess support (the app's deny rules for data/config/includes/tools/vendor rely on it)
RUN a2enmod rewrite headers \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY . /var/www/html/

# The app stores its data as JSON files, so Apache's user must be able to write there
RUN mkdir -p /var/www/html/session-system/data/backups \
 && chown -R www-data:www-data /var/www/html/session-system/data

EXPOSE 80
