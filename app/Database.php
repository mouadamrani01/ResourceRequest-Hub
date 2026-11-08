<?php
declare(strict_types=1);

namespace ResourceHub;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        // Docker / CI use environment variables. Local development can use config.local.php.
        $configFile = dirname(__DIR__) . '/config.local.php';
        $local = is_file($configFile) ? (require $configFile) : [];
        $db = $local['database'] ?? [];
        $host = getenv('DB_HOST') ?: ($db['host'] ?? '');
        $port = getenv('DB_PORT') ?: ($db['port'] ?? 3306);
        $name = getenv('DB_NAME') ?: ($db['name'] ?? '');
        $username = getenv('DB_USER') ?: ($db['username'] ?? '');
        $password = getenv('DB_PASSWORD');
        $password = $password !== false ? $password : ($db['password'] ?? '');

        if ($host === '' || $name === '' || $username === '') {
            throw new RuntimeException('Database is not configured. See README.md.');
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('PHP extension pdo_mysql is required.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $name);
        self::$instance = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$instance->exec("SET time_zone = '+00:00'");
        return self::$instance;
    }
}
