<?php
/**
 * MySQL connection settings.
 *
 * Fill these in with your actual database credentials. On most hosts
 * (cPanel, Plesk, etc.) DB_HOST is "localhost", and DB_NAME/DB_USER are
 * whatever you named them when you created the database and its user.
 *
 * Local testing example (matches schema.sql if you run it manually):
 *   DB_HOST = 'localhost'
 *   DB_NAME = 'ssms_db'
 *   DB_USER = 'ssms_user'
 *   DB_PASS = 'ssms_pass_change_me'
 */

define('SSMS_DB_HOST', getenv('SSMS_DB_HOST') ?: 'localhost');
define('SSMS_DB_NAME', getenv('SSMS_DB_NAME') ?: 'ssms_db');
define('SSMS_DB_USER', getenv('SSMS_DB_USER') ?: 'ssms_user');
define('SSMS_DB_PASS', getenv('SSMS_DB_PASS') ?: 'ssms_pass_change_me');
define('SSMS_DB_PORT', getenv('SSMS_DB_PORT') ?: '3306');
