<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$asset = __DIR__ . $path;
if ($path !== '/' && is_file($asset)) {
    return false;
}
require __DIR__ . '/index.php';
