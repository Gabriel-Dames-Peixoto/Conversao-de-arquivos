<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;
use RuntimeException;

final class JsonConverter extends AbstractConverter implements ConverterInterface
{
    public function supports(array $detection): bool
    {
        return $detection['detected_type'] === 'json';
    }

    public function getName(): string
    {
        return 'JsonConverter';
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        $this->ensureReadableAndNotEmpty($filePath, 'JSON');

        $content = file_get_contents($filePath);
        if (trim((string) $content) === '') {
            throw new RuntimeException('O arquivo JSON esta vazio.');
        }

        try {
            $decoded = json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('JSON invalido: ' . $exception->getMessage());
        }

        $rows = [];

        if (is_array($decoded) && $this->isListArray($decoded)) {
            foreach ($decoded as $item) {
                $rows[] = is_array($item) ? $item : ['value' => $item];
            }
        } elseif (is_array($decoded)) {
            $rows[] = $decoded;
        } else {
            $rows[] = ['value' => $decoded];
        }

        $columns = $this->collectColumns($rows);
        $normalizedRows = [];

        foreach ($rows as $row) {
            $flattenedRow = is_array($row) ? $this->flattenArray($row) : ['value' => $this->normalizeScalar($row)];
            $normalizedRow = [];
            foreach ($columns as $column) {
                $normalizedRow[$column] = $this->normalizeScalar($flattenedRow[$column] ?? '');
            }
            $normalizedRows[] = $normalizedRow;
        }

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => 'JSON importado com sucesso.',
            'warnings' => [],
            'error' => null,
            'metadata' => $this->baseMetadata($detection, $originalFilename),
            'normalized_data' => $this->buildNormalizedData($columns, $normalizedRows, $this->baseMetadata($detection, $originalFilename)),
        ];
    }

    private function collectColumns(array $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            $flattenedRow = is_array($row) ? $this->flattenArray($row) : ['value' => $row];
            foreach (array_keys($flattenedRow) as $key) {
                if (!in_array($key, $columns, true)) {
                    $columns[] = (string) $key;
                }
            }
        }

        return $columns === [] ? ['value'] : $columns;
    }
}
