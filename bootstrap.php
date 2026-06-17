<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $sessionPath = __DIR__ . '/storage/sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0775, true);
    }
    session_save_path($sessionPath);
    session_start();
}

require __DIR__ . '/src/Support/helpers.php';
require __DIR__ . '/src/Support/Autoloader.php';

\App\Support\Autoloader::register(__DIR__ . '/src');

$appConfig = require __DIR__ . '/config/app.php';
$databaseConfig = require __DIR__ . '/config/database.php';

$GLOBALS['app_config'] = $appConfig;
$GLOBALS['database_config'] = $databaseConfig;

$schemaManager = new \App\Database\SchemaManager($databaseConfig, __DIR__ . '/database/schema.sql');
$databaseError = null;

try {
    $schemaManager->ensureDatabaseAndSchema();
} catch (\Throwable $exception) {
    $databaseError = $exception->getMessage();
}

return [
    'app_config' => $appConfig,
    'database_config' => $databaseConfig,
    'database_error' => $databaseError,
];
