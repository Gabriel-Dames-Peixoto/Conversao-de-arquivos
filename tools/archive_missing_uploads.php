<?php

declare(strict_types=1);

$bootstrap = require __DIR__ . '/../bootstrap.php';

use App\Repositories\NormalizedDataRepository;
use App\Repositories\ProcessingLogRepository;
use App\Repositories\UploadedFileRepository;

if ($bootstrap['database_error'] !== null) {
    fwrite(STDERR, 'Banco de dados indisponivel: ' . $bootstrap['database_error'] . PHP_EOL);
    exit(1);
}

$uploadRepository = new UploadedFileRepository();
$normalizedRepository = new NormalizedDataRepository();
$logRepository = new ProcessingLogRepository();
$archived = 0;

foreach ($uploadRepository->latestIncludingArchived(1000) as $upload) {
    if ($upload['status'] === 'archived_missing') {
        continue;
    }

    $path = (string) ($upload['storage_path'] ?? '');
    if ($path !== '' && is_file($path)) {
        continue;
    }

    $metadata = [
        'original_filename' => (string) ($upload['original_filename'] ?? ''),
        'extension' => (string) ($upload['extension'] ?? ''),
        'mime_type' => (string) ($upload['mime_type'] ?? ''),
        'detected_type' => (string) ($upload['detected_type'] ?? ''),
        'storage_path' => $path,
        'source_available' => false,
        'previous_status' => (string) ($upload['status'] ?? ''),
        'archived_at' => date('Y-m-d H:i:s'),
    ];

    $normalizedRepository->deleteForUpload((int) $upload['id']);
    $uploadRepository->archiveMissingSource((int) $upload['id'], $metadata);
    $logRepository->create(
        (int) $upload['id'],
        'archive',
        'success',
        'Registro arquivado automaticamente porque o arquivo original nao esta mais disponivel.',
        $metadata
    );

    $archived++;
}

echo 'Arquivados: ' . $archived . PHP_EOL;
