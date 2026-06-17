<?php

declare(strict_types=1);

return [
    'name' => 'Conversor de Arquivos para Importacao',
    'base_url' => getenv('APP_BASE_URL') ?: '',
    'max_upload_size' => 20 * 1024 * 1024,
    'preview_rows' => 20,
    'storage' => [
        'uploads' => __DIR__ . '/../storage/uploads',
        'exports' => __DIR__ . '/../storage/exports',
    ],
    'export_formats' => ['csv', 'json', 'xml', 'xlsx'],
];
