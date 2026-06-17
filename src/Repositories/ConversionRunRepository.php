<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;

final class ConversionRunRepository
{
    public function create(array $data): int
    {
        $statement = Connection::getInstance()->prepare(
            'INSERT INTO conversion_runs (
                upload_id, converter_name, detected_type, status, message, warnings_json, error_message
            ) VALUES (
                :upload_id, :converter_name, :detected_type, :status, :message, :warnings_json, :error_message
            )'
        );

        $statement->execute([
            'upload_id' => $data['upload_id'],
            'converter_name' => $data['converter_name'],
            'detected_type' => $data['detected_type'],
            'status' => $data['status'],
            'message' => $data['message'],
            'warnings_json' => json_encode($data['warnings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'error_message' => $data['error_message'],
        ]);

        return (int) Connection::getInstance()->lastInsertId();
    }

    public function forUpload(int $uploadId): array
    {
        $statement = Connection::getInstance()->prepare(
            'SELECT * FROM conversion_runs WHERE upload_id = :upload_id ORDER BY id DESC'
        );
        $statement->execute(['upload_id' => $uploadId]);

        $rows = $statement->fetchAll();

        return array_map(
            static function (array $row): array {
                $row['warnings'] = $row['warnings_json'] !== null
                    ? json_decode($row['warnings_json'], true, 512, JSON_THROW_ON_ERROR)
                    : [];

                return $row;
            },
            $rows
        );
    }
}
