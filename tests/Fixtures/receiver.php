<?php

declare(strict_types=1);

/*
 * A receiver for the integration tests, started with `php -S`: it logs every request as one JSON line
 * and answers with the status the path asks for (/status/503), a redirect (/redirect), or a Retry-After (/status/429).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$log = getenv('HORNET_RECEIVER_LOG') ?: sys_get_temp_dir().'/hornet-receiver.log';

file_put_contents($log, json_encode([
    'path' => $path,
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body' => file_get_contents('php://input'),
]).PHP_EOL, FILE_APPEND | LOCK_EX);

if ($path === '/redirect') {
    header('Location: /status/200', true, 302);

    return;
}

if (preg_match('#^/status/(\d{3})$#', $path, $match) === 1) {
    http_response_code((int) $match[1]);

    if ($match[1] === '429') {
        header('Retry-After: 7');
    }

    echo '{"ok":'.($match[1][0] === '2' ? 'true' : 'false').'}';

    return;
}

http_response_code(200);
