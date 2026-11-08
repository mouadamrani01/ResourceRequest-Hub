<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use ResourceHub\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$db = Database::connection();
$check = $db->query("SHOW COLUMNS FROM resource_requests LIKE 'resource_pool_id'");
if ($check->fetch()) {
    fwrite(STDOUT, "Platform schema already present. No migration applied.\n");
    exit(0);
}

$sql = file_get_contents(dirname(__DIR__) . '/database/migrations/002_platform.sql');
if ($sql === false) {
    fwrite(STDERR, "Migration file not found.\n");
    exit(1);
}

// MySQL DDL implicitly commits. Back up the original database before running.
foreach (explode(';', $sql) as $statement) {
    $statement = trim($statement);
    if ($statement === '' || preg_match('/^(?:--[^\n]*\n?\s*)+$/', $statement)) {
        continue;
    }
    $db->exec($statement);
}
fwrite(STDOUT, "Platform migration applied successfully.\n");
