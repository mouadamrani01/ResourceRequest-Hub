<?php
declare(strict_types=1);

namespace ResourceHub;

use PDO;

final class Auth
{
    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT id, name, email, role FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            Http::redirect('/login');
        }
        return $user;
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT id, password_hash FROM users WHERE email = ? AND active = 1');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        unset($_SESSION['login_attempts'], $_SESSION['login_since']);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
