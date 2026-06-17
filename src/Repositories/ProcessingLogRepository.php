<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;

final class ProcessingLogRepository
{
    public function create(int $uploadId, string $stage, string $status, string $message, ?array $context = null): void
    {
        $statement = Connection::getInstance()->prepare(
            'INSERT INTO processing_logs (upload_id, stage, status, message, context_json)
             VALUES (:upload_id, :stage, :status, :message, :context_json)'
        );

        $statement->execute([
            'upload_id' => $uploadId,
            'stage' => $stage,
            'status' => $status,
            'message' => $message,
            'context_json' => $context === null ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    public function forUpload(int $uploadId): array
    {
        $statement = Connection::getInstance()->prepare(
            'SELECT * FROM processing_logs WHERE upload_id = :upload_id ORDER BY id ASC'
        );
        $statement->execute(['upload_id' => $uploadId]);

        return $statement->fetchAll();
    }
}
