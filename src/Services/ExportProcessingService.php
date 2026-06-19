<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApplicationException;
use App\Repositories\ExportedFileRepository;
use PDOException;
use Throwable;

final class ExportProcessingService
{
    /** @var array<string,mixed> */
    private $appConfig;

    public function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    public function process(array $upload, array $normalized, string $format): array
    {
        $this->ensureManualReviewCompleted($normalized);

        $exportRepository = new ExportedFileRepository();

        try {
            $exportId = $exportRepository->create([
                'upload_id' => $upload['id'],
                'format' => $format,
                'original_export_name' => pathinfo($upload['original_filename'], PATHINFO_FILENAME) . '.' . $format,
                'stored_filename' => '',
                'storage_path' => '',
                'status' => 'processing',
            ]);
        } catch (PDOException $exception) {
            throw new ApplicationException('Falha ao registrar a exportacao no banco de dados.');
        }

        try {
            $exportService = new ExportService($this->appConfig);
            $result = $exportService->export($upload, $normalized, $format);
            $exportRepository->markAsCompleted($exportId, $result['original_export_name'], $result['stored_filename'], $result['storage_path']);

            return $result;
        } catch (Throwable $exception) {
            try {
                $exportRepository->markAsFailed($exportId, $exception->getMessage());
            } catch (Throwable) {
            }

            throw $exception instanceof ApplicationException
                ? $exception
                : new ApplicationException('Erro ao exportar o arquivo: ' . $exception->getMessage());
        }
    }

    private function ensureManualReviewCompleted(array $normalized): void
    {
        $metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];
        $review = is_array($metadata['manual_review'] ?? null) ? $metadata['manual_review'] : null;

        if ($review === null || ($review['required'] ?? false) !== true) {
            return;
        }

        if (($review['status'] ?? '') === 'reviewed') {
            return;
        }

        throw new ApplicationException('Conclua a revisao manual dos campos pendentes antes de exportar o arquivo final.');
    }
}
