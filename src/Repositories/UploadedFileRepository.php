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
        $statement = Connection::getInstance()->prepare(
            "SELECT * FROM uploaded_files WHERE status <> 'archived_missing' ORDER BY id DESC LIMIT :limit"
        );
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function latestIncludingArchived(int $limit): array
    {
        $statement = Connection::getInstance()->prepare('SELECT * FROM uploaded_files ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function search(array $filters, int $limit, string $tab): array
    {
        $criteria = $this->buildSearchCriteria($filters, $tab);
        $where = $criteria['where'];
        $parameters = $criteria['parameters'];

        $statement = Connection::getInstance()->prepare(
            'SELECT * FROM uploaded_files WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT :limit'
        );

        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }

        $statement->bindValue(':limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function countSearch(array $filters, string $tab): int
    {
        $criteria = $this->buildSearchCriteria($filters, $tab);
        $where = $criteria['where'];
        $parameters = $criteria['parameters'];

        $statement = Connection::getInstance()->prepare(
            'SELECT COUNT(*) FROM uploaded_files WHERE ' . implode(' AND ', $where)
        );

        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function countByTab(string $tab): int
    {
        $where = $this->tabConditions($tab);

        $statement = Connection::getInstance()->prepare(
            'SELECT COUNT(*) FROM uploaded_files WHERE ' . implode(' AND ', $where)
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function listFilterValues(string $column, string $tab): array
    {
        if (!in_array($column, ['detected_type', 'status'], true)) {
            throw new \InvalidArgumentException('Filtro nao permitido.');
        }

        $where = $this->tabConditions($tab);
        $where[] = $column . ' IS NOT NULL';
        $where[] = $column . " <> ''";

        $statement = Connection::getInstance()->prepare(
            'SELECT DISTINCT ' . $column . ' FROM uploaded_files WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $column
        );
        $statement->execute();

        return array_map(static function (array $row) use ($column): string {
            return (string) $row[$column];
        }, $statement->fetchAll());
    }

    public function archiveMissingSource(int $uploadId, array $metadata): void
    {
        $statement = Connection::getInstance()->prepare(
            "UPDATE uploaded_files
             SET status = 'archived_missing',
                 converter_name = :converter_name,
                 error_message = NULL,
                 metadata_json = :metadata_json
             WHERE id = :id"
        );

        $statement->execute([
            'id' => $uploadId,
            'converter_name' => 'MissingSourceRecord',
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    public function find(int $id): ?array
    {
        $statement = Connection::getInstance()->prepare('SELECT * FROM uploaded_files WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $result = $statement->fetch();

        return $result === false ? null : $result;
    }

    private function buildSearchCriteria(array $filters, string $tab): array
    {
        $where = $this->tabConditions($tab);
        $parameters = [];

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $where[] = 'original_filename LIKE :name';
            $parameters['name'] = '%' . $name . '%';
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $where[] = 'detected_type = :detected_type';
            $parameters['detected_type'] = $type;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status = :status';
            $parameters['status'] = $status;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'DATE(created_at) >= :date_from';
            $parameters['date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'DATE(created_at) <= :date_to';
            $parameters['date_to'] = $dateTo;
        }

        return [
            'where' => $where,
            'parameters' => $parameters,
        ];
    }

    private function tabConditions(string $tab): array
    {
        if ($tab === 'archived') {
            return ["status = 'archived_missing'"];
        }

        return ["status <> 'archived_missing'"];
    }
}
