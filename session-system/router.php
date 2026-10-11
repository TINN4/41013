<?php
/**
 * Router for PHP's built-in development server:  php -S 127.0.0.1:8080 router.php   (run from session-system/)
 * The built-in server ignores .htaccess, so this blocks private folders the same way.
 * NOT for production: use Apache/Nginx with HTTPS (see docs/SETUP_GUIDE.md).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rawurldecode($path);
if ((preg_match('#(^|/)(data|config|includes|tools|tests|backups|vendor)(/|$)|(^|/)\.#', $path) && !preg_match('#(^|/)assets/vendor/#', $path))) {
    http_response_code(404); echo 'Not found'; return true;
}
if ($path === '/' ) { header('Location: login.php'); return true; }
$file = __DIR__ . $path;
if (is_file($file) && !preg_match('/\.(json|log|env|md|bak|sql)$/i', $path)) return false;   // static or PHP file: let the server handle it
http_response_code(404); echo 'Not found'; return true;
