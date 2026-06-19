<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;
use App\Support\OcrTextExtractor;
use App\Support\PdfImageRenderer;
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
            $ocrExtraction = $this->extractScannedPdfText($filePath);
            $warnings = array_merge($warnings, $ocrExtraction['warnings']);

            if (trim($ocrExtraction['text']) === '') {
                $warnings[] = 'Nao foi encontrado texto extraivel no PDF. OCR ou preenchimento manual sera necessario para PDFs escaneados.';
                $metadata = array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    [
                        'ocr_available' => (bool) ($ocrExtraction['ocr_available'] ?? false),
                        'ocr_performed' => (bool) ($ocrExtraction['ocr_performed'] ?? false),
                        'ocr_required' => true,
                        'ocr_low_confidence' => false,
                        'ocr_confidence' => null,
                        'ocr_pages' => (int) ($ocrExtraction['pages'] ?? 0),
                        'extraction_method' => $extractionMethod . '+ocr',
                    ]
                );

                return [
                    'status' => 'processed',
                    'converter' => $this->getName(),
                    'message' => 'PDF aceito e registrado para revisao manual porque nao possui texto extraivel.',
                    'warnings' => array_values(array_unique($warnings)),
                    'error' => null,
                    'metadata' => $metadata,
                    'normalized_data' => $this->buildNormalizedData(['text'], [], $metadata),
                ];
            }

            $extraction['text'] = $ocrExtraction['text'];
            $extractionMethod = 'pdf_page_ocr';
            $extraction['ocr'] = $ocrExtraction;
        }

        $tableRows = $extractor->extractTabularRows($extraction['text']);
        $ocr = is_array($extraction['ocr'] ?? null) ? $extraction['ocr'] : [];
        $ocrConfidence = is_numeric($ocr['confidence'] ?? null) ? (float) $ocr['confidence'] : null;
        $ocrLowConfidence = $ocrConfidence !== null && $ocrConfidence < OcrTextExtractor::LOW_CONFIDENCE_THRESHOLD;

        if ($tableRows !== []) {
            $headers = array_keys($tableRows[0]);
            $normalizedData = $this->buildNormalizedData(
                $headers,
                $tableRows,
                $this->metadataForExtractedPdf($detection, $originalFilename, true, $extractionMethod, $ocr, $ocrLowConfidence)
            );
        } else {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $extraction['text']) ?: []), static fn (string $line): bool => $line !== ''));
            $normalizedData = $this->buildNormalizedData(
                ['text'],
                array_map(static fn (string $line): array => ['text' => $line], $lines),
                $this->metadataForExtractedPdf($detection, $originalFilename, false, $extractionMethod, $ocr, $ocrLowConfidence)
            );
        }

        if ($ocrLowConfidence) {
            $warnings[] = 'OCR do PDF executado com baixa confianca. Revise os dados extraidos antes da exportacao final.';
        }

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => $warnings === []
                ? 'PDF importado com texto extraido com sucesso.'
                : 'PDF importado com texto extraido e avisos registrados.',
            'warnings' => array_values(array_unique($warnings)),
            'error' => null,
            'metadata' => $this->metadataForExtractedPdf($detection, $originalFilename, $tableRows !== [], $extractionMethod, $ocr, $ocrLowConfidence),
            'normalized_data' => $normalizedData,
        ];
    }

    private function extractScannedPdfText(string $filePath): array
    {
        $renderer = new PdfImageRenderer();
        $rendered = $renderer->renderToImages($filePath);
        $warnings = is_array($rendered['warnings'] ?? null) ? $rendered['warnings'] : [];
        $images = is_array($rendered['images'] ?? null) ? $rendered['images'] : [];

        if ($images === []) {
            return [
                'text' => '',
                'warnings' => $warnings,
                'ocr_available' => false,
                'ocr_performed' => false,
                'confidence' => null,
                'pages' => 0,
            ];
        }

        $ocrExtractor = new OcrTextExtractor();
        $texts = [];
        $confidences = [];
        $ocrAvailable = false;

        try {
            foreach ($images as $pageIndex => $imagePath) {
                $ocr = $ocrExtractor->extractImage((string) $imagePath);
                $ocrAvailable = $ocrAvailable || (($ocr['available'] ?? false) === true);
                $pageText = trim((string) ($ocr['text'] ?? ''));

                if ($pageText !== '') {
                    $texts[] = 'Pagina ' . ((int) $pageIndex + 1) . PHP_EOL . $pageText;
                }

                if (is_numeric($ocr['confidence'] ?? null)) {
                    $confidences[] = (float) $ocr['confidence'];
                }

                if (is_array($ocr['warnings'] ?? null)) {
                    $warnings = array_merge($warnings, $ocr['warnings']);
                }
            }
        } finally {
            $renderer->cleanup(is_string($rendered['temp_dir'] ?? null) ? $rendered['temp_dir'] : null);
        }

        return [
            'text' => trim(implode(PHP_EOL . PHP_EOL, $texts)),
            'warnings' => array_values(array_unique($warnings)),
            'ocr_available' => $ocrAvailable,
            'ocr_performed' => $ocrAvailable,
            'confidence' => $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 2),
            'pages' => count($images),
        ];
    }

    private function metadataForExtractedPdf(
        array $detection,
        string $originalFilename,
        bool $tableExtraction,
        string $extractionMethod,
        array $ocr,
        bool $ocrLowConfidence
    ): array {
        $ocrPerformed = $ocr !== [];

        return array_merge(
            $this->baseMetadata($detection, $originalFilename),
            [
                'table_extraction' => $tableExtraction,
                'ocr_available' => $ocrPerformed ? (bool) ($ocr['ocr_available'] ?? false) : null,
                'ocr_performed' => $ocrPerformed ? (bool) ($ocr['ocr_performed'] ?? false) : false,
                'ocr_required' => false,
                'ocr_low_confidence' => $ocrLowConfidence,
                'ocr_confidence' => is_numeric($ocr['confidence'] ?? null) ? (float) $ocr['confidence'] : null,
                'ocr_pages' => (int) ($ocr['pages'] ?? 0),
                'extraction_method' => $extractionMethod,
            ]
        );
    }
}
