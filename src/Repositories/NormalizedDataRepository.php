<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;

final class NormalizedDataRepository
{
    public function deleteForUpload(int $uploadId): void
    {
        Connection::getInstance()
            ->prepare('DELETE FROM normalized_data WHERE upload_id = :upload_id')
            ->execute(['upload_id' => $uploadId]);
    }

    public function replaceForUpload(int $uploadId, array $normalizedData): int
    {
        $pdo = Connection::getInstance();
        $this->deleteForUpload($uploadId);

        $statement = $pdo->prepare(
            'INSERT INTO normalized_data (
                upload_id, columns_json, rows_json, metadata_json, total_rows, total_columns
            ) VALUES (
                :upload_id, :columns_json, :rows_json, :metadata_json, :total_rows, :total_columns
            )'
        );

        $statement->execute([
            'upload_id' => $uploadId,
            'columns_json' => json_encode($normalizedData['columns'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'rows_json' => json_encode($normalizedData['rows'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'metadata_json' => json_encode($normalizedData['metadata'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'total_rows' => $normalizedData['metadata']['total_rows'],
            'total_columns' => $normalizedData['metadata']['total_columns'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findByUploadId(int $uploadId): ?array
    {
        $statement = Connection::getInstance()->prepare('SELECT * FROM normalized_data WHERE upload_id = :upload_id LIMIT 1');
        $statement->execute(['upload_id' => $uploadId]);
        $result = $statement->fetch();

        if ($result === false) {
            return null;
        }

        return array_merge($result, [
            'columns' => json_decode($result['columns_json'], true, 512, JSON_THROW_ON_ERROR),
            'rows' => json_decode($result['rows_json'], true, 512, JSON_THROW_ON_ERROR),
            'metadata' => json_decode($result['metadata_json'], true, 512, JSON_THROW_ON_ERROR),
        ]);
    }
}
