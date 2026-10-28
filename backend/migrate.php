<?php

declare(strict_types=1);

/**
 * Applies pending SQL migrations in backend/migrations, then exits.
 * Runs on every api container boot (see docker/php/entrypoint.sh).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/src/bootstrap.php';

use App\Config;
use App\Database;

$attempts = 0;
$pdo = null;

while ($pdo === null) {
    try {
        $pdo = Database::connect();
    } catch (PDOException $e) {
        $attempts++;
        if ($attempts >= 40) {
            fwrite(STDERR, 'Database is not reachable: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        sleep(1);
    }
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        name       TEXT PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )'
);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

$applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip(array_map('strval', $applied));

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read migration {$name}" . PHP_EOL);
        exit(1);
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        $pdo->commit();
        fwrite(STDOUT, "Applied migration: {$name}" . PHP_EOL);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "Migration {$name} failed: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, 'Migrations up to date (db=' . Config::get('DB_NAME', 'webhooks') . ')' . PHP_EOL);
