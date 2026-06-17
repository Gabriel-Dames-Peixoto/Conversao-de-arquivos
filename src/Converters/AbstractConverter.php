<?php

declare(strict_types=1);

namespace App\Converters;

abstract class AbstractConverter
{
    protected function ensureReadableAndNotEmpty(string $filePath, string $label): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException("Nao foi possivel ler o arquivo {$label}.");
        }

        $size = filesize($filePath);
        if ($size === false || $size === 0) {
            throw new \RuntimeException("O arquivo {$label} esta vazio.");
        }
    }

    protected function buildNormalizedData(array $columns, array $rows, array $metadata): array
    {
        $normalizedRows = array_map(
            static function (array $row) use ($columns): array {
                $normalized = [];

                foreach ($columns as $column) {
                    $normalized[$column] = isset($row[$column]) ? (string) $row[$column] : '';
                }

                return $normalized;
            },
            $rows
        );

        return [
            'columns' => array_values($columns),
            'rows' => array_values($normalizedRows),
            'metadata' => array_merge($metadata, [
                'total_rows' => count($normalizedRows),
                'total_columns' => count($columns),
                'processed_at' => date('Y-m-d H:i:s'),
            ]),
        ];
    }

    protected function normalizeScalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    protected function baseMetadata(array $detection, string $originalFilename): array
    {
        return [
            'original_filename' => $originalFilename,
            'extension' => $detection['extension'],
            'mime_type' => $detection['mime_type'],
            'detected_type' => $detection['detected_type'],
            'file_size' => $detection['file_size'] ?? null,
        ];
    }

    protected function flattenArray(array $row, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($row as $key => $value) {
            $field = $prefix . (string) $key;

            if (is_array($value) && !$this->isListArray($value)) {
                $flattened += $this->flattenArray($value, $field . '.');
                continue;
            }

            if (is_array($value) && $this->isListArray($value)) {
                $flattened[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                continue;
            }

            $flattened[$field] = $this->normalizeScalar($value);
        }

        return $flattened;
    }

    protected function isListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
