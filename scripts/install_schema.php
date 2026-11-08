<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use ResourceHub\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
if ($sql === false) {
    fwrite(STDERR, "Cannot read schema file.\n");
    exit(1);
}

// Our schema contains plain CREATE TABLE statements, not stored procedures or
// semicolons inside string literals. Splitting on ';' is sufficient here.
$count = 0;
foreach (explode(';', $sql) as $statement) {
    $statement = trim($statement);
    if ($statement === '' || preg_match('/^(?:--[^\n]*\n?\s*)+$/', $statement)) {
        continue;
    }
    Database::connection()->exec($statement);
    $count++;
}
fwrite(STDOUT, "Database schema loaded ({$count} statements).\n");
