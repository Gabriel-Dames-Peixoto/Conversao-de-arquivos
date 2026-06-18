<?php

declare(strict_types=1);

$bootstrap = require __DIR__ . '/bootstrap.php';

use App\Services\StoredUploadReprocessingService;

$databaseError = $bootstrap['database_error'];
$uploadId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($databaseError !== null) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Banco de dados indisponivel: ' . $databaseError,
    ];

    header('Location: file.php?id=' . $uploadId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: file.php?id=' . $uploadId);
    exit;
}

try {
    $result = (new StoredUploadReprocessingService($bootstrap['app_config']))->reprocess($uploadId);

    $_SESSION['flash'] = [
        'type' => $result['status'] === 'failed' ? 'error' : 'success',
        'message' => 'Reprocessamento concluido: ' . $result['message'],
    ];
} catch (\Throwable $exception) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Falha ao reprocessar: ' . $exception->getMessage(),
    ];
}

header('Location: file.php?id=' . $uploadId);
exit;
