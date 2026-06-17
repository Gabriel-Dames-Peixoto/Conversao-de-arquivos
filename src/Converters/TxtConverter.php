<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;

final class TxtConverter extends AbstractConverter implements ConverterInterface
{
    public function supports(array $detection): bool
    {
        return $detection['detected_type'] === 'txt';
    }

    public function getName(): string
    {
        return 'TxtConverter';
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        $this->ensureReadableAndNotEmpty($filePath, 'TXT');

        $content = file_get_contents($filePath);
        $lines = preg_split('/\R/u', (string) $content) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

        if ($lines === []) {
            throw new \RuntimeException('O arquivo TXT esta vazio.');
        }

        $detectedDelimiter = $this->detectDelimiter($lines);
        $columns = $detectedDelimiter === null ? ['text'] : [];
        $rows = [];
        $warnings = [];

        foreach ($lines as $lineIndex => $line) {
            if ($detectedDelimiter === null) {
                $rows[] = ['text' => $line];
                continue;
            }

            $parts = $detectedDelimiter === 'MULTISPACE'
                ? preg_split('/\s{2,}/', $line) ?: []
                : explode($detectedDelimiter, $line);
            $parts = array_map('trim', $parts);

            if ($lineIndex === 0) {
                foreach ($parts as $index => $part) {
                    $columns[] = $part !== '' ? $part : 'column_' . ($index + 1);
                }

                continue;
            }

            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $parts[$index] ?? '';
            }

             if (count($parts) !== count($columns)) {
                $warnings[] = 'Foi detectada ao menos uma linha TXT com estrutura diferente do cabecalho; os valores ausentes foram preenchidos com vazio.';
            }
            $rows[] = $row;
        }

        if ($columns === []) {
            $columns = ['text'];
        }

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => 'TXT processado com sucesso.',
            'warnings' => array_values(array_unique(array_merge(
                $detectedDelimiter === null ? ['Nenhum delimitador estruturado foi detectado; o texto foi salvo em coluna unica.'] : [],
                $warnings
            ))),
            'error' => null,
            'metadata' => array_merge(
                $this->baseMetadata($detection, $originalFilename),
                ['delimiter' => $detectedDelimiter]
            ),
            'normalized_data' => $this->buildNormalizedData(
                $columns,
                $rows,
                array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    ['delimiter' => $detectedDelimiter]
                )
            ),
        ];
    }

    private function detectDelimiter(array $lines): ?string
    {
        $sample = array_slice($lines, 0, 3);

        foreach (["\t", ';', ',', '|'] as $delimiter) {
            $counts = array_map(static fn (string $line): int => substr_count($line, $delimiter), $sample);
            if ($counts !== [] && min($counts) > 0) {
                return $delimiter;
            }
        }

        foreach ($sample as $line) {
            if (preg_match('/\s{2,}/', $line) === 1) {
                return 'MULTISPACE';
            }
        }

        return null;
    }
}
