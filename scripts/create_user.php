<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use ResourceHub\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php scripts/create_user.php 'Full Name' email@example.com admin|member\n");
    exit(1);
}
[$script, $name, $email, $role] = $argv;
$email = strtolower(trim($email));
if (strlen(trim($name)) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin', 'member'], true)) {
    fwrite(STDERR, "Invalid name, email or role.\n");
    exit(1);
}

fwrite(STDOUT, 'Password (at least 12 characters): ');
$password = rtrim((string)fgets(STDIN), "\r\n");
if (strlen($password) < 12) {
    fwrite(STDERR, "Password too short.\n");
    exit(1);
}
$stmt = Database::connection()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
$stmt->execute([trim($name), $email, password_hash($password, PASSWORD_DEFAULT), $role]);
fwrite(STDOUT, "User created successfully.\n");
