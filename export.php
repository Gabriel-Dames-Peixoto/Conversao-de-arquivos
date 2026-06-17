<?php

declare(strict_types=1);

$bootstrap = require __DIR__ . '/bootstrap.php';

use App\Repositories\ExportedFileRepository;
use App\Repositories\NormalizedDataRepository;
use App\Repositories\UploadedFileRepository;
use App\Services\ExportProcessingService;

$databaseError = $bootstrap['database_error'];

if ($databaseError !== null) {
    http_response_code(500);
    echo 'Banco de dados indisponivel: ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8');
    exit;
}

$uploadId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$format = isset($_GET['format']) ? strtolower((string) $_GET['format']) : '';

$uploadRepository = new UploadedFileRepository();
$normalizedRepository = new NormalizedDataRepository();
$upload = $uploadRepository->find($uploadId);
$normalized = $normalizedRepository->findByUploadId($uploadId);

if ($upload === null || $normalized === null) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Nao foi possivel exportar porque o arquivo ou os dados normalizados nao foram encontrados.',
    ];

    header('Location: file.php?id=' . $uploadId);
    exit;
}

try {
    $exportProcessingService = new ExportProcessingService($bootstrap['app_config']);
    $result = $exportProcessingService->process($upload, $normalized, $format);

    header('Content-Type: ' . $result['mime_type']);
    header('Content-Disposition: attachment; filename="' . $result['original_export_name'] . '"');
    header('Content-Length: ' . filesize($result['storage_path']));
    readfile($result['storage_path']);
    exit;
} catch (\Throwable $exception) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'Falha na exportacao: ' . $exception->getMessage(),
    ];

    header('Location: file.php?id=' . $uploadId);
    exit;
}
