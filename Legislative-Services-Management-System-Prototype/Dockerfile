FROM php:8.3-apache

# --- PHP extensions the app actually uses ---
# pdo_mysql: session-system/includes/store.php (PDO MySQL backend)
RUN docker-php-ext-install pdo_mysql

# --- Apache config ---
# Serve the project root so both the static prototype (index.html, app.html,
# js/, css/) and the PHP backend (session-system/) are reachable from the
# same document root, exactly as the app's own links expect
# (index.html -> "session-system/login.php", login.php -> "../index.html").
RUN a2enmod rewrite headers

# Copy the whole project into Apache's web root.
COPY . /var/www/html/

# session-system/data/ and session-system/backups/ already ship their own
# .htaccess files denying direct web access (schema/dump/backups should
# never be reachable by URL) — make sure Apache actually honors .htaccess
# by allowing AllowOverride in the default vhost.
RUN { \
    echo '<Directory /var/www/html/>'; \
    echo '    AllowOverride All'; \
    echo '    Require all granted'; \
    echo '</Directory>'; \
    } > /etc/apache2/conf-available/lsms-overrides.conf \
    && a2enconf lsms-overrides

# Never bake real credentials into the image — set these as environment
# variables in the platform's Environment Variables tab instead:
#   SSMS_DB_HOST, SSMS_DB_NAME, SSMS_DB_USER, SSMS_DB_PASS, SSMS_DB_PORT
# includes/db_config.php reads them via getenv() and only falls back to
# the placeholder localhost/ssms_db/ssms_user values if unset.

EXPOSE 80

# Apache's default CMD (apache2-foreground) already runs from the base
# image, so nothing further is needed here.
