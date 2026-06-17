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

        if (trim($extraction['text']) === '') {
            $warnings[] = 'Nao foi encontrado texto extraivel no PDF. OCR seria necessario para PDFs escaneados.';

            return [
                'status' => 'processed_with_warning',
                'converter' => $this->getName(),
                'message' => 'PDF aceito e registrado, mas sem texto extraivel.',
                'warnings' => $warnings,
                'error' => null,
                'metadata' => array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    ['ocr_required' => true]
                ),
                'normalized_data' => $this->buildNormalizedData(
                    ['text'],
                    [],
                    array_merge(
                        $this->baseMetadata($detection, $originalFilename),
                        ['ocr_required' => true]
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
                    ['table_extraction' => true]
                )
            );
        } else {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $extraction['text']) ?: []), static fn (string $line): bool => $line !== ''));
            $normalizedData = $this->buildNormalizedData(
                ['text'],
                array_map(static fn (string $line): array => ['text' => $line], $lines),
                array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    ['table_extraction' => false]
                )
            );
        }

        return [
            'status' => 'processed_with_warning',
            'converter' => $this->getName(),
            'message' => 'PDF processado com tentativa de extracao de texto.',
            'warnings' => $warnings,
            'error' => null,
            'metadata' => array_merge(
                $this->baseMetadata($detection, $originalFilename),
                [
                    'table_extraction' => $tableRows !== [],
                    'ocr_required' => false,
                ]
            ),
            'normalized_data' => $normalizedData,
        ];
    }
}
