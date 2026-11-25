<?php

declare(strict_types=1);

/*
 * The router of PHP's built-in server for WordPress with pretty permalinks: a file that exists is served as it is,
 * anything else goes to index.php, as the rewrite rules of Apache or Nginx would do.
 */

// A second server plays an HTTPS site: PHP's built-in server cannot do TLS, WordPress only reads $_SERVER['HTTPS']
if (getenv('E2E_HTTPS') === '1') {
    $_SERVER['HTTPS'] = 'on';
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = ($_SERVER['DOCUMENT_ROOT'] ?? '').$path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (is_dir($file) && is_file(rtrim($file, '/').'/index.php')) {
    $_SERVER['SCRIPT_NAME'] = rtrim($path, '/').'/index.php';
    $_SERVER['SCRIPT_FILENAME'] = rtrim($file, '/').'/index.php';
    require rtrim($file, '/').'/index.php';

    return true;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = ($_SERVER['DOCUMENT_ROOT'] ?? '').'/index.php';
require $_SERVER['SCRIPT_FILENAME'];
