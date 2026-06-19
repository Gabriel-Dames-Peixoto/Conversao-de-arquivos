<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;
use App\Support\FixedWidthScheduleExtractor;
use RuntimeException;

final class CsvConverter extends AbstractConverter implements ConverterInterface
{
    public function supports(array $detection): bool
    {
        return $detection['detected_type'] === 'csv';
    }

    public function getName(): string
    {
        return 'CsvConverter';
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        $this->ensureReadableAndNotEmpty($filePath, 'CSV');

        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel abrir o CSV.');
        }

        $sampleLines = [];
        while (($line = fgets($handle)) !== false && count($sampleLines) < 5) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $sampleLines[] = $trimmed;
            }
        }
        rewind($handle);

        if ($sampleLines === []) {
            fclose($handle);
            throw new RuntimeException('O arquivo CSV esta vazio.');
        }

        $structured = $this->tryFixedWidthSchedule($filePath, $detection, $originalFilename);
        if ($structured !== null) {
            fclose($handle);

            return $structured;
        }

        $delimiter = $this->detectDelimiter($sampleLines);
        $headers = fgetcsv($handle, 0, $delimiter) ?: [];
        $headers[0] = isset($headers[0]) ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?: (string) $headers[0] : 'column_1';
        $headers = $this->normalizeHeaders($headers);
        $rows = [];
        $warnings = [];

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            $row = [];
            if (count($line) !== count($headers)) {
                $warnings[] = 'Foi detectada ao menos uma linha com numero de colunas diferente do cabecalho; os dados foram ajustados.';
            }

            foreach ($headers as $index => $header) {
                $row[$header] = isset($line[$index]) ? trim((string) $line[$index]) : '';
            }

            $rows[] = $row;
        }

        fclose($handle);

        if ($rows === []) {
            $warnings[] = 'O CSV possui cabecalho, mas nao possui linhas de dados.';
        }

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => $rows === [] ? 'CSV processado, mas sem linhas de dados.' : 'CSV importado com sucesso.',
            'warnings' => array_values(array_unique($warnings)),
            'error' => null,
            'metadata' => array_merge(
                $this->baseMetadata($detection, $originalFilename),
                ['delimiter' => $delimiter]
            ),
            'normalized_data' => $this->buildNormalizedData(
                $headers,
                $rows,
                array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    ['delimiter' => $delimiter]
                )
            ),
        ];
    }

    private function detectDelimiter(array $lines): string
    {
        $scores = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = array_map(static fn (string $line): int => substr_count($line, $candidate), $lines);
            $positiveCounts = array_filter($counts, static fn (int $count): bool => $count > 0);
            $scores[$candidate] = $positiveCounts === [] ? 0 : array_sum($positiveCounts) + count($positiveCounts);
        }

        arsort($scores);
        $delimiter = (string) array_key_first($scores);

        return $scores[$delimiter] > 0 ? $delimiter : ',';
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $index => $header) {
            $cleanHeader = trim((string) $header);
            $normalized[] = $cleanHeader !== '' ? $cleanHeader : 'column_' . ($index + 1);
        }

        return $normalized === [] ? ['column_1'] : $normalized;
    }

    private function tryFixedWidthSchedule(string $filePath, array $detection, string $originalFilename): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $lines = preg_split('/\R/u', $content) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
        $baseMetadata = $this->baseMetadata($detection, $originalFilename);
        $structured = (new FixedWidthScheduleExtractor())->extract($lines, $baseMetadata);

        if ($structured === null) {
            return null;
        }

        return [
            'status' => 'processed',
            'converter' => 'FixedWidthScheduleConverter',
            'message' => 'Arquivo de largura fixa identificado e organizado por produto e data.',
            'warnings' => $structured['warnings'],
            'error' => null,
            'metadata' => $structured['metadata'],
            'normalized_data' => $this->buildNormalizedData(
                $structured['columns'],
                $structured['rows'],
                $structured['metadata']
            ),
        ];
    }
}
