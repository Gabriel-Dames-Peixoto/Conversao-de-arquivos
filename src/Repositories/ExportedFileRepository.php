<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;

final class ExportedFileRepository
{
    public function create(array $data): int
    {
        $statement = Connection::getInstance()->prepare(
            'INSERT INTO exported_files (
                upload_id, format, original_export_name, stored_filename, storage_path, status
            ) VALUES (
                :upload_id, :format, :original_export_name, :stored_filename, :storage_path, :status
            )'
        );

        $statement->execute($data);

        return (int) Connection::getInstance()->lastInsertId();
    }

    public function markAsCompleted(int $id, string $originalExportName, string $storedFilename, string $storagePath): void
    {
        $statement = Connection::getInstance()->prepare(
            'UPDATE exported_files
             SET original_export_name = :original_export_name,
                 stored_filename = :stored_filename,
                 storage_path = :storage_path,
                 status = :status
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'original_export_name' => $originalExportName,
            'stored_filename' => $storedFilename,
            'storage_path' => $storagePath,
            'status' => 'completed',
        ]);
    }

    public function markAsFailed(int $id, string $errorMessage): void
    {
        $statement = Connection::getInstance()->prepare(
            'UPDATE exported_files SET status = :status, error_message = :error_message WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function forUpload(int $uploadId): array
    {
        $statement = Connection::getInstance()->prepare(
            'SELECT * FROM exported_files WHERE upload_id = :upload_id ORDER BY id DESC'
        );
        $statement->execute(['upload_id' => $uploadId]);

        return $statement->fetchAll();
    }
}
