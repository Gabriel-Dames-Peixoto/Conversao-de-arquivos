<?php

declare(strict_types=1);

namespace App\Support;

final class ReadabilityAnalyzer
{
    /** @var array<int,array<string,mixed>> */
    private array $documentReviewFields;

    public function __construct(array $documentReviewFields = [])
    {
        $this->documentReviewFields = $documentReviewFields !== [] ? $documentReviewFields : [
            ['key' => 'document_summary', 'label' => 'Resumo ou informacoes principais', 'required' => true],
            ['key' => 'document_identifier', 'label' => 'Numero ou identificador do documento', 'required' => false],
            ['key' => 'document_date', 'label' => 'Data principal', 'required' => false],
            ['key' => 'document_total', 'label' => 'Valor total', 'required' => false],
        ];
    }

    public function analyze(?array $normalizedData, array $conversionResult): array
    {
        $metadata = is_array($normalizedData['metadata'] ?? null) ? $normalizedData['metadata'] : [];
        $columns = is_array($normalizedData['columns'] ?? null) ? $normalizedData['columns'] : [];
        $rows = is_array($normalizedData['rows'] ?? null) ? $normalizedData['rows'] : [];
        $warnings = is_array($conversionResult['warnings'] ?? null) ? $conversionResult['warnings'] : [];

        $issues = [];
        $fields = [];
        $warningHits = $this->countReadingWarnings($warnings);
        $detectedType = (string) ($metadata['detected_type'] ?? '');
        $ocrRequired = ($metadata['ocr_required'] ?? false) === true;
        $ocrLowConfidence = ($metadata['ocr_low_confidence'] ?? false) === true
            || (is_numeric($metadata['ocr_confidence'] ?? null) && (float) $metadata['ocr_confidence'] < OcrTextExtractor::LOW_CONFIDENCE_THRESHOLD);
        $scanLikeWithoutRows = $rows === [] && in_array($detectedType, ['pdf', 'image'], true);
        $documentReviewRequired = $ocrRequired || $ocrLowConfidence || $scanLikeWithoutRows;

        if ($documentReviewRequired) {
            $issues[] = [
                'area' => 'Documento inteiro',
                'reason' => $this->documentReviewReason($ocrRequired, $ocrLowConfidence, $detectedType),
                'severity' => 'high',
            ];

            foreach ($this->documentReviewFields as $field) {
                $fields[] = [
                    'key' => (string) ($field['key'] ?? $this->makeKey((string) ($field['label'] ?? 'campo'))),
                    'label' => (string) ($field['label'] ?? 'Campo manual'),
                    'reason' => $this->documentFieldReason($ocrRequired, $ocrLowConfidence),
                    'current_value' => '',
                    'manual_value' => '',
                    'type' => 'document_field',
                    'required' => (bool) ($field['required'] ?? false),
                    'resolved' => false,
                ];
            }
        }

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($columns as $column) {
                if (count($fields) >= 40) {
                    break 2;
                }

                $columnName = (string) $column;
                $value = trim((string) ($row[$columnName] ?? ''));
                $reason = $this->detectProblem($columnName, $value);

                if ($reason === null) {
                    continue;
                }

                $issues[] = [
                    'area' => 'Linha ' . ((int) $rowIndex + 1) . ', coluna ' . $columnName,
                    'reason' => $reason,
                    'severity' => 'medium',
                ];

                $fields[] = [
                    'key' => 'row_' . ((int) $rowIndex + 1) . '__' . $this->makeKey($columnName),
                    'label' => 'Linha ' . ((int) $rowIndex + 1) . ' - ' . $columnName,
                    'reason' => $reason,
                    'current_value' => $value,
                    'manual_value' => '',
                    'type' => 'cell',
                    'row_index' => (int) $rowIndex,
                    'column' => $columnName,
                    'required' => true,
                    'resolved' => false,
                ];
            }
        }

        $totalCells = max(1, count($rows) * max(1, count($columns)));
        $cellPenalty = min(0.55, count($fields) / $totalCells);
        $warningPenalty = min(0.25, $warningHits * 0.08);
        $ocrPenalty = $ocrRequired || $scanLikeWithoutRows ? 0.7 : ($ocrLowConfidence ? 0.35 : 0.0);
        $confidence = max(0.05, round(1 - $cellPenalty - $warningPenalty - $ocrPenalty, 2));
        $required = $issues !== [] || $confidence < 0.72;

        if ($required && $fields === []) {
            $fields[] = [
                'key' => 'reading_note',
                'label' => 'Observacao sobre a leitura',
                'reason' => 'O processamento registrou avisos de leitura e precisa de confirmacao manual.',
                'current_value' => '',
                'manual_value' => '',
                'type' => 'document_field',
                'required' => true,
                'resolved' => false,
            ];
        }

        return [
            'required' => $required,
            'status' => $required ? 'pending' : 'not_required',
            'confidence' => $confidence,
            'summary' => $required
                ? 'Foram encontrados campos pendentes de revisao. Preencha ou confirme os campos indicados antes da exportacao final.'
                : 'Leitura concluida sem areas criticas para revisao manual.',
            'issues' => array_slice($issues, 0, 40),
            'fields' => $fields,
            'requested_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function detectProblem(string $column, string $value): ?string
    {
        if ($value === '' && $this->isImportantColumn($column)) {
            return 'Campo importante veio vazio apos a leitura automatica.';
        }

        if ($value === '') {
            return null;
        }

        if (preg_match('/\x{FFFD}|\?{2,}|#{3,}|_{3,}|\[(?:i)?legivel\]/iu', $value) === 1) {
            return 'O valor contem marcadores comuns de texto ilegivel.';
        }

        $length = max(1, function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value));
        $readableChars = preg_match_all('/[\p{L}\p{N}]/u', $value) ?: 0;

        if ($length >= 8 && ($readableChars / $length) < 0.35) {
            return 'O valor possui poucos caracteres reconheciveis para o tamanho do campo.';
        }

        return null;
    }

    private function documentReviewReason(bool $ocrRequired, bool $ocrLowConfidence, string $detectedType): string
    {
        if ($ocrRequired) {
            return $detectedType === 'image'
                ? 'A imagem parece escaneada e nao teve texto extraido automaticamente.'
                : 'O arquivo parece escaneado ou sem texto extraivel automaticamente.';
        }

        if ($ocrLowConfidence) {
            return 'O OCR foi executado, mas a confianca estimada ficou baixa.';
        }

        return 'O documento nao gerou linhas legiveis automaticamente.';
    }

    private function documentFieldReason(bool $ocrRequired, bool $ocrLowConfidence): string
    {
        if ($ocrRequired) {
            return 'Preenchimento manual solicitado porque o texto nao foi extraido com confianca.';
        }

        if ($ocrLowConfidence) {
            return 'Confirme ou substitua manualmente porque o OCR ficou com baixa confianca.';
        }

        return 'Preenchimento manual solicitado porque a leitura automatica nao gerou dados confiaveis.';
    }

    private function isImportantColumn(string $column): bool
    {
        return preg_match(
            '/nome|cliente|fornecedor|documento|numero|data|valor|total|cpf|cnpj|codigo|quantidade|descricao|produto/i',
            $column
        ) === 1;
    }

    private function countReadingWarnings(array $warnings): int
    {
        $count = 0;

        foreach ($warnings as $warning) {
            if (preg_match('/ocr|extraivel|legivel|estrutura diferente|valores ausentes|corrompido/i', (string) $warning) === 1) {
                $count++;
            }
        }

        return $count;
    }

    private function makeKey(string $label): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?: '');
        $key = trim($key, '_');

        return $key !== '' ? $key : 'campo_manual';
    }
}
