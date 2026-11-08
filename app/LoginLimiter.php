<?php
declare(strict_types=1);

namespace ResourceHub;

use PDO;

/**
 * Login throttling shared by all PHP sessions.
 * Each bucket is scoped to one normalized email and one client IP address.
 */
final class LoginLimiter
{
    private const MAX_FAILURES = 8;

    public function __construct(private readonly PDO $db) {}

    public function allows(string $email, string $ip): bool
    {
        $statement = $this->db->prepare(
            'SELECT failed_attempts, (window_started_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE) AS in_window
             FROM login_throttles WHERE bucket = ?'
        );
        $statement->execute([$this->bucket($email, $ip)]);
        $attempt = $statement->fetch();

        return !$attempt || !(bool) $attempt['in_window'] || (int) $attempt['failed_attempts'] < self::MAX_FAILURES;
    }

    public function recordFailure(string $email, string $ip): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO login_throttles (bucket, failed_attempts, window_started_at)
             VALUES (?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                failed_attempts = IF(window_started_at <= UTC_TIMESTAMP() - INTERVAL 15 MINUTE,
                                     1, LEAST(255, failed_attempts + 1)),
                window_started_at = IF(window_started_at <= UTC_TIMESTAMP() - INTERVAL 15 MINUTE,
                                       UTC_TIMESTAMP(), window_started_at)'
        );
        $statement->execute([$this->bucket($email, $ip)]);
    }

    public function clear(string $email, string $ip): void
    {
        $statement = $this->db->prepare('DELETE FROM login_throttles WHERE bucket = ?');
        $statement->execute([$this->bucket($email, $ip)]);
    }

    public static function bucket(string $email, string $ip): string
    {
        return hash('sha256', strtolower(trim($email)) . "\0" . $ip);
    }
}
