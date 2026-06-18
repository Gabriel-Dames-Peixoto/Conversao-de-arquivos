<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;
use App\Support\PdfTextExtractor;

final class PdfConverter extends AbstractConverter implements ConverterInterface
{
    public function supports(array $detection): bool
    {
        return $detection['detected_type'] === 'pdf';
    }

    public function getName(): string
    {
        return 'PdfConverter';
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        $this->ensureReadableAndNotEmpty($filePath, 'PDF');

        $header = file_get_contents($filePath, false, null, 0, 4);
        if ($header !== '%PDF') {
            throw new \RuntimeException('O arquivo PDF parece corrompido ou nao possui assinatura valida.');
        }

        $extractor = new PdfTextExtractor();
        $extraction = $extractor->extract($filePath);
        $warnings = $extraction['warnings'];
        $extractionMethod = (string) ($extraction['method'] ?? 'unknown');

        if (trim($extraction['text']) === '') {
            $warnings[] = 'Nao foi encontrado texto extraivel no PDF. OCR seria necessario para PDFs escaneados.';

            return [
                'status' => 'processed',
                'converter' => $this->getName(),
                'message' => 'PDF aceito e registrado para revisao manual porque nao possui texto extraivel.',
                'warnings' => $warnings,
                'error' => null,
                'metadata' => array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    [
                        'ocr_required' => true,
                        'extraction_method' => $extractionMethod,
                    ]
                ),
                'normalized_data' => $this->buildNormalizedData(
                    ['text'],
                    [],
                    array_merge(
                        $this->baseMetadata($detection, $originalFilename),
                        [
                            'ocr_required' => true,
                            'extraction_method' => $extractionMethod,
                        ]
                    )
                ),
            ];
        }

        $tableRows = $extractor->extractTabularRows($extraction['text']);

        if ($tableRows !== []) {
            $headers = array_keys($tableRows[0]);
            $normalizedData = $this->buildNormalizedData(
                $headers,
                $tableRows,
                array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    [
                        'table_extraction' => true,
                        'extraction_method' => $extractionMethod,
                    ]
                )
            );
        } else {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $extraction['text']) ?: []), static fn (string $line): bool => $line !== ''));
            $normalizedData = $this->buildNormalizedData(
                ['text'],
                array_map(static fn (string $line): array => ['text' => $line], $lines),
                array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    [
                        'table_extraction' => false,
                        'extraction_method' => $extractionMethod,
                    ]
                )
            );
        }

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => $warnings === []
                ? 'PDF importado com texto extraido com sucesso.'
                : 'PDF importado com texto extraido e avisos registrados.',
            'warnings' => $warnings,
            'error' => null,
            'metadata' => array_merge(
                $this->baseMetadata($detection, $originalFilename),
                [
                    'table_extraction' => $tableRows !== [],
                    'ocr_required' => false,
                    'extraction_method' => $extractionMethod,
                ]
            ),
            'normalized_data' => $normalizedData,
        ];
    }
}
