<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'ResourceHub\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}
