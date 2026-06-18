<?php

declare(strict_types=1);

$bootstrap = require __DIR__ . '/bootstrap.php';

use App\Repositories\UploadedFileRepository;

$databaseError = $bootstrap['database_error'];

if ($databaseError !== null) {
    http_response_code(500);
    echo 'Banco de dados indisponivel: ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8');
    exit;
}

$uploadId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$upload = (new UploadedFileRepository())->find($uploadId);

if ($upload === null) {
    http_response_code(404);
    echo 'Arquivo nao encontrado.';
    exit;
}

if ($upload['status'] === 'archived_missing') {
    http_response_code(404);
    echo 'Arquivo original nao esta mais disponivel.';
    exit;
}

$path = (string) ($upload['storage_path'] ?? '');

if ($path === '' || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    echo 'Arquivo original nao esta mais disponivel no armazenamento.';
    exit;
}

$filename = str_replace(["\r", "\n", '"'], '_', (string) $upload['original_filename']);
$mimeType = (string) ($upload['mime_type'] ?: 'application/octet-stream');
$fileSize = filesize($path);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addcslashes($filename, '\\"') . '"');
header('Content-Length: ' . ($fileSize === false ? '0' : (string) $fileSize));
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
