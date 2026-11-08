<?php
declare(strict_types=1);

namespace ResourceHub;

final class Http
{
    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $provided = $_POST['_csrf'] ?? '';
        if (!is_string($provided) || !hash_equals(self::csrfToken(), $provided)) {
            self::abort(419, 'Security check failed. Refresh the page and try again.');
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    public static function abort(int $code, string $message): never
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
