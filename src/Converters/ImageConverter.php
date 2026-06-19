<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;
use App\Support\OcrTextExtractor;

final class ImageConverter extends AbstractConverter implements ConverterInterface
{
    public function supports(array $detection): bool
    {
        return ($detection['detected_type'] ?? '') === 'image';
    }

    public function getName(): string
    {
        return 'ImageConverter';
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        $this->ensureReadableAndNotEmpty($filePath, 'imagem');

        $ocr = (new OcrTextExtractor())->extractImage($filePath);
        $text = trim((string) ($ocr['text'] ?? ''));
        $confidence = is_numeric($ocr['confidence'] ?? null) ? (float) $ocr['confidence'] : null;
        $ocrAvailable = ($ocr['available'] ?? false) === true;
        $ocrLowConfidence = $confidence !== null && $confidence < OcrTextExtractor::LOW_CONFIDENCE_THRESHOLD;
        $warnings = is_array($ocr['warnings'] ?? null) ? $ocr['warnings'] : [];

        if ($text === '') {
            $warnings[] = $ocrAvailable
                ? 'Nenhum texto legivel foi encontrado na imagem escaneada. Preenchimento manual sera necessario.'
                : 'OCR nao esta disponivel para ler a imagem escaneada. Preenchimento manual sera necessario.';
        }

        $metadata = array_merge(
            $this->baseMetadata($detection, $originalFilename),
            [
                'ocr_available' => $ocrAvailable,
                'ocr_performed' => $ocrAvailable,
                'ocr_required' => $text === '',
                'ocr_low_confidence' => $ocrLowConfidence,
                'ocr_confidence' => $confidence,
                'ocr_word_count' => (int) ($ocr['word_count'] ?? 0),
                'extraction_method' => 'image_ocr',
                'ocr_language' => (string) ($ocr['language'] ?? ''),
            ]
        );

        $rows = $text === ''
            ? []
            : array_map(
                static fn (string $line): array => ['text' => $line],
                array_values(array_filter(
                    array_map('trim', preg_split('/\R/u', $text) ?: []),
                    static fn (string $line): bool => $line !== ''
                ))
            );

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => $this->messageFor($text, $ocrAvailable, $ocrLowConfidence),
            'warnings' => array_values(array_unique($warnings)),
            'error' => null,
            'metadata' => $metadata,
            'normalized_data' => $this->buildNormalizedData(['text'], $rows, $metadata),
        ];
    }

    private function messageFor(string $text, bool $ocrAvailable, bool $ocrLowConfidence): string
    {
        if ($text === '' && !$ocrAvailable) {
            return 'Imagem aceita e registrada para revisao manual porque o OCR nao esta disponivel.';
        }

        if ($text === '') {
            return 'Imagem aceita e registrada para revisao manual porque o OCR nao encontrou texto legivel.';
        }

        if ($ocrLowConfidence) {
            return 'Imagem processada por OCR com baixa confianca. Revise os campos indicados antes da exportacao final.';
        }

        return 'Imagem processada por OCR com texto extraido com sucesso.';
    }
}
