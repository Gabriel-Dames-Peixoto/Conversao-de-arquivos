<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApplicationException;

final class FileUploadService
{
    /** @var array<string,mixed> */
    private $appConfig;

    public function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    public function storeUploadedFile(array $uploadedFile): array
    {
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApplicationException('Falha no upload do arquivo.');
        }

        if (($uploadedFile['size'] ?? 0) > $this->appConfig['max_upload_size']) {
            throw new ApplicationException('O arquivo excede o tamanho maximo permitido.');
        }

        $originalFilename = (string) ($uploadedFile['name'] ?? 'arquivo');
        $sanitizedFilename = $this->sanitizeFilename($originalFilename);
        $extension = strtolower(pathinfo($sanitizedFilename, PATHINFO_EXTENSION));
        $storedFilename = sprintf('%s_%s%s', date('YmdHis'), bin2hex(random_bytes(8)), $extension !== '' ? '.' . $extension : '');
        $targetDirectory = $this->appConfig['storage']['uploads'];

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new ApplicationException('Nao foi possivel criar o diretorio de uploads.');
        }

        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $storedFilename;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            throw new ApplicationException('Nao foi possivel salvar o upload com seguranca.');
        }

        return [
            'original_filename' => $originalFilename,
            'sanitized_filename' => $sanitizedFilename,
            'stored_filename' => $storedFilename,
            'storage_path' => $targetPath,
            'extension' => $extension,
            'file_size' => (int) $uploadedFile['size'],
            'checksum_sha256' => hash_file('sha256', $targetPath) ?: null,
        ];
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^\w\-.]+/u', '_', $filename) ?: 'arquivo';
        $filename = trim($filename, '._');

        return $filename !== '' ? $filename : 'arquivo';
    }
}
