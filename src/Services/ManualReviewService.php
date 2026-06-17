<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApplicationException;
use App\Repositories\NormalizedDataRepository;
use App\Repositories\ProcessingLogRepository;
use App\Repositories\UploadedFileRepository;

final class ManualReviewService
{
    public function save(array $upload, array $manualFields): array
    {
        $uploadId = (int) ($upload['id'] ?? 0);
        $normalizedRepository = new NormalizedDataRepository();
        $normalized = $normalizedRepository->findByUploadId($uploadId);

        if ($uploadId <= 0 || $normalized === null) {
            throw new ApplicationException('Nao foi possivel localizar os dados normalizados para revisao.');
        }

        $metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];
        $review = is_array($metadata['manual_review'] ?? null) ? $metadata['manual_review'] : null;

        if ($review === null) {
            throw new ApplicationException('Este arquivo nao possui uma revisao manual pendente.');
        }

        $columns = is_array($normalized['columns'] ?? null) ? $normalized['columns'] : [];
        $rows = is_array($normalized['rows'] ?? null) ? $normalized['rows'] : [];
        $fields = is_array($review['fields'] ?? null)
            ? array_values(array_filter($review['fields'], 'is_array'))
            : [];
        $manualDocumentRows = [];

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            $manualValue = trim((string) ($manualFields[$key] ?? ''));
            $isRequired = (bool) ($field['required'] ?? false);
            $field['manual_value'] = $manualValue;
            $field['resolved'] = $manualValue !== '' || $isRequired === false;
            $field['updated_at'] = date('Y-m-d H:i:s');

            if ($manualValue !== '' && ($field['type'] ?? '') === 'cell') {
                $rowIndex = (int) ($field['row_index'] ?? -1);
                $column = (string) ($field['column'] ?? '');

                if ($rowIndex >= 0 && $column !== '' && isset($rows[$rowIndex]) && is_array($rows[$rowIndex])) {
                    $rows[$rowIndex][$column] = $manualValue;
                    $field['current_value'] = $manualValue;
                }
            }

            if ($manualValue !== '' && ($field['type'] ?? '') === 'document_field') {
                $manualDocumentRows[] = [
                    'campo' => (string) ($field['label'] ?? $key),
                    'valor' => $manualValue,
                    'origem' => 'preenchimento_manual',
                ];
            }

            $fields[$index] = $field;
        }

        if ($manualDocumentRows !== [] && ($rows === [] || ($metadata['ocr_required'] ?? false) === true)) {
            $columns = ['campo', 'valor', 'origem'];
            $rows = $manualDocumentRows;
        }

        $requiredFields = array_values(array_filter(
            $fields,
            static fn (array $field): bool => (bool) ($field['required'] ?? false)
        ));
        $resolvedRequiredFields = array_values(array_filter(
            $requiredFields,
            static fn (array $field): bool => (bool) ($field['resolved'] ?? false)
        ));
        $status = count($requiredFields) === count($resolvedRequiredFields) ? 'reviewed' : 'pending';

        $review['fields'] = array_values($fields);
        $review['status'] = $status;
        $review['resolved_count'] = count($resolvedRequiredFields);
        $review['required_count'] = count($requiredFields);
        $review['updated_at'] = date('Y-m-d H:i:s');

        if ($status === 'reviewed') {
            $review['reviewed_at'] = date('Y-m-d H:i:s');
            $review['summary'] = 'Revisao manual concluida pelo usuario.';
        }

        $metadata['manual_review'] = $review;
        $metadata['total_rows'] = count($rows);
        $metadata['total_columns'] = count($columns);

        $normalizedRepository->replaceForUpload($uploadId, [
            'columns' => $columns,
            'rows' => $rows,
            'metadata' => $metadata,
        ]);

        $newUploadStatus = $status === 'reviewed' ? 'reviewed' : 'processed_with_warning';
        (new UploadedFileRepository())->updateProcessingResult(
            $uploadId,
            $newUploadStatus,
            (string) ($upload['converter_name'] ?? 'ManualReview'),
            $upload['error_message'] ?? null,
            $metadata
        );

        (new ProcessingLogRepository())->create(
            $uploadId,
            'manual_review',
            $status === 'reviewed' ? 'success' : 'warning',
            $status === 'reviewed'
                ? 'Revisao manual concluida e dados atualizados.'
                : 'Revisao manual salva, mas ainda existem campos obrigatorios pendentes.',
            ['manual_review' => $review]
        );

        return [
            'status' => $status,
            'message' => $status === 'reviewed'
                ? 'Revisao manual salva com sucesso.'
                : 'Revisao manual salva. Ainda existem campos obrigatorios pendentes.',
        ];
    }
}
