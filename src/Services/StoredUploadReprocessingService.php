<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Exceptions\ApplicationException;
use App\Repositories\ConversionRunRepository;
use App\Repositories\NormalizedDataRepository;
use App\Repositories\ProcessingLogRepository;
use App\Repositories\UploadedFileRepository;
use App\Support\ReadabilityAnalyzer;
use Throwable;

final class StoredUploadReprocessingService
{
    /** @var array<string,mixed> */
    private array $appConfig;

    public function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    public function reprocess(int $uploadId): array
    {
        $uploadRepository = new UploadedFileRepository();
        $normalizedRepository = new NormalizedDataRepository();
        $logRepository = new ProcessingLogRepository();
        $conversionRunRepository = new ConversionRunRepository();
        $upload = $uploadRepository->find($uploadId);

        if ($upload === null) {
            throw new ApplicationException('Arquivo nao encontrado para reprocessamento.');
        }

        $filePath = (string) ($upload['storage_path'] ?? '');
        if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
            return $this->archiveMissingSource($upload);
        }

        $pdo = Connection::getInstance();

        try {
            $pdo->beginTransaction();

            $logRepository->create($uploadId, 'reprocess', 'success', 'Reprocessamento iniciado pelo usuario.');

            $detection = (new FileDetectionService())->detect($filePath, (string) $upload['original_filename']);
            $uploadRepository->updateDetection($uploadId, $detection);
            $logRepository->create($uploadId, 'detection', 'success', 'Tipo de arquivo reidentificado.', $detection);

            $importContextService = new ImportContextService();
            $conversionResult = (new FileConversionService())->convert($filePath, $detection, (string) $upload['original_filename']);
            $conversionResult = $importContextService->applyToConversionResult(
                $conversionResult,
                $importContextService->fromUpload($upload)
            );
            $conversionResult = $this->attachReadabilityAnalysis($conversionResult);
            $conversionResult = $this->normalizeReviewStatus($conversionResult);

            if ($conversionResult['normalized_data'] !== null) {
                $normalizedRepository->replaceForUpload($uploadId, $conversionResult['normalized_data']);
            } else {
                $normalizedRepository->deleteForUpload($uploadId);
            }

            $uploadRepository->updateProcessingResult(
                $uploadId,
                $conversionResult['status'],
                $conversionResult['converter'],
                $conversionResult['error'],
                $conversionResult['metadata']
            );

            $conversionRunRepository->create([
                'upload_id' => $uploadId,
                'converter_name' => $conversionResult['converter'],
                'detected_type' => $detection['detected_type'],
                'status' => $conversionResult['status'],
                'message' => $conversionResult['message'],
                'warnings' => $conversionResult['warnings'],
                'error_message' => $conversionResult['error'],
            ]);

            $logRepository->create(
                $uploadId,
                'conversion',
                $conversionResult['status'],
                $conversionResult['message'],
                [
                    'warnings' => $conversionResult['warnings'],
                    'converter' => $conversionResult['converter'],
                    'reprocessed' => true,
                ]
            );

            $pdo->commit();

            return [
                'status' => $conversionResult['status'],
                'message' => $conversionResult['message'],
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            try {
                $uploadRepository->updateProcessingResult(
                    $uploadId,
                    'failed',
                    'System',
                    $exception->getMessage(),
                    ['reprocess_error' => true]
                );
                $logRepository->create($uploadId, 'reprocess', 'failed', $exception->getMessage());
            } catch (Throwable) {
            }

            throw $exception instanceof ApplicationException
                ? $exception
                : new ApplicationException('Falha ao reprocessar o arquivo: ' . $exception->getMessage());
        }
    }

    private function attachReadabilityAnalysis(array $conversionResult): array
    {
        if (($conversionResult['normalized_data'] ?? null) === null) {
            return $conversionResult;
        }

        $analyzer = new ReadabilityAnalyzer($this->appConfig['manual_review_fields'] ?? []);
        $manualReview = $analyzer->analyze($conversionResult['normalized_data'], $conversionResult);

        $conversionResult['normalized_data']['metadata']['manual_review'] = $manualReview;
        $conversionResult['metadata']['manual_review'] = $manualReview;

        if (($manualReview['required'] ?? false) === true) {
            $conversionResult['warnings'][] = 'Existem campos pendentes de revisao. Revise e preencha manualmente os campos indicados.';

            $conversionResult['message'] .= ' Revise os campos indicados antes da exportacao final.';
        }

        $conversionResult['warnings'] = array_values(array_unique($conversionResult['warnings']));

        return $conversionResult;
    }

    private function normalizeReviewStatus(array $conversionResult): array
    {
        $metadata = is_array($conversionResult['metadata'] ?? null) ? $conversionResult['metadata'] : [];
        $manualReview = is_array($metadata['manual_review'] ?? null) ? $metadata['manual_review'] : null;

        if (
            $manualReview !== null
            && ($manualReview['required'] ?? false) === true
            && ($manualReview['status'] ?? '') !== 'reviewed'
            && !in_array((string) ($conversionResult['status'] ?? ''), ['failed', 'unsupported'], true)
        ) {
            $conversionResult['status'] = 'pending';
        }

        return $conversionResult;
    }

    private function archiveMissingSource(array $upload): array
    {
        $uploadId = (int) $upload['id'];
        $metadata = [
            'original_filename' => (string) ($upload['original_filename'] ?? ''),
            'extension' => (string) ($upload['extension'] ?? ''),
            'mime_type' => (string) ($upload['mime_type'] ?? ''),
            'detected_type' => (string) ($upload['detected_type'] ?? 'missing_source'),
            'storage_path' => (string) ($upload['storage_path'] ?? ''),
            'source_available' => false,
            'previous_status' => (string) ($upload['status'] ?? ''),
            'total_rows' => 3,
            'total_columns' => 2,
            'processed_at' => date('Y-m-d H:i:s'),
        ];

        $normalizedRepository = new NormalizedDataRepository();
        $uploadRepository = new UploadedFileRepository();
        $logRepository = new ProcessingLogRepository();

        $normalizedRepository->deleteForUpload($uploadId);
        $uploadRepository->archiveMissingSource($uploadId, $metadata);

        $logRepository->create(
            $uploadId,
            'archive',
            'success',
            'Registro arquivado porque o arquivo original nao esta mais disponivel.',
            $metadata
        );

        return [
            'status' => 'archived_missing',
            'message' => 'Registro arquivado porque o arquivo original esta ausente.',
        ];
    }
}
