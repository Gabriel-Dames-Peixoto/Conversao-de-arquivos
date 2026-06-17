<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;

final class UploadedFileRepository
{
    public function create(array $data): int
    {
        $sql = 'INSERT INTO uploaded_files (
                    original_filename, sanitized_filename, stored_filename, storage_path,
                    extension, file_size, checksum_sha256, status
                ) VALUES (
                    :original_filename, :sanitized_filename, :stored_filename, :storage_path,
                    :extension, :file_size, :checksum_sha256, :status
                )';

        $statement = Connection::getInstance()->prepare($sql);
        $statement->execute($data);

        return (int) Connection::getInstance()->lastInsertId();
    }

    public function updateDetection(int $uploadId, array $detection): void
    {
        $sql = 'UPDATE uploaded_files
                SET extension = :extension,
                    mime_type = :mime_type,
                    detected_type = :detected_type,
                    metadata_json = :metadata_json
                WHERE id = :id';

        $statement = Connection::getInstance()->prepare($sql);
        $statement->execute([
            'id' => $uploadId,
            'extension' => $detection['extension'],
            'mime_type' => $detection['mime_type'],
            'detected_type' => $detection['detected_type'],
            'metadata_json' => json_encode($detection, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    public function updateProcessingResult(
        int $uploadId,
        string $status,
        string $converter,
        ?string $errorMessage,
        array $metadata
    ): void {
        $sql = 'UPDATE uploaded_files
                SET status = :status,
                    converter_name = :converter_name,
                    error_message = :error_message,
                    metadata_json = :metadata_json
                WHERE id = :id';

        $statement = Connection::getInstance()->prepare($sql);
        $statement->execute([
            'id' => $uploadId,
            'status' => $status,
            'converter_name' => $converter,
            'error_message' => $errorMessage,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    public function latest(int $limit): array
    {
        $statement = Connection::getInstance()->prepare('SELECT * FROM uploaded_files ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = Connection::getInstance()->prepare('SELECT * FROM uploaded_files WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $result = $statement->fetch();

        return $result === false ? null : $result;
    }
}
