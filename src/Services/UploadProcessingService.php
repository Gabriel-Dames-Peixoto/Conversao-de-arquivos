<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Exceptions\ApplicationException;
use App\Repositories\ConversionRunRepository;
use App\Repositories\NormalizedDataRepository;
use App\Repositories\ProcessingLogRepository;
use App\Repositories\UploadedFileRepository;
use PDOException;
use Throwable;

final class UploadProcessingService
{
    /** @var array<string,mixed> */
    private $appConfig;

    public function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    public function process(array $uploadedFile): array
    {
        $uploadService = new FileUploadService($this->appConfig);
        $storedFile = $uploadService->storeUploadedFile($uploadedFile);

        return $this->processStoredFile($storedFile);
    }

    public function processStoredFile(array $storedFile): array
    {
        $detectionService = new FileDetectionService();
        $conversionService = new FileConversionService();
        $uploadRepository = new UploadedFileRepository();
        $normalizedRepository = new NormalizedDataRepository();
        $logRepository = new ProcessingLogRepository();
        $conversionRunRepository = new ConversionRunRepository();
        $pdo = Connection::getInstance();
        $uploadId = null;

        try {
            $pdo->beginTransaction();

            $uploadId = $uploadRepository->create([
                'original_filename' => $storedFile['original_filename'],
                'sanitized_filename' => $storedFile['sanitized_filename'],
                'stored_filename' => $storedFile['stored_filename'],
                'storage_path' => $storedFile['storage_path'],
                'extension' => $storedFile['extension'],
                'file_size' => $storedFile['file_size'],
                'checksum_sha256' => $storedFile['checksum_sha256'],
                'status' => 'uploaded',
            ]);

            $logRepository->create($uploadId, 'upload', 'success', 'Arquivo enviado com sucesso.');

            $detection = $detectionService->detect($storedFile['storage_path'], $storedFile['original_filename']);
            $uploadRepository->updateDetection($uploadId, $detection);
            $logRepository->create($uploadId, 'detection', 'success', 'Tipo de arquivo identificado.', $detection);

            $conversionResult = $conversionService->convert($storedFile['storage_path'], $detection, $storedFile['original_filename']);

            if ($conversionResult['normalized_data'] !== null) {
                $normalizedRepository->replaceForUpload($uploadId, $conversionResult['normalized_data']);
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
                ]
            );

            $pdo->commit();

            return [
                'upload_id' => $uploadId,
                'status' => $conversionResult['status'],
                'message' => $conversionResult['message'],
            ];
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->cleanupStoredFile($storedFile['storage_path'] ?? null);
            $this->markUploadAsFailed($uploadId, 'Falha ao salvar informacoes no banco de dados: ' . $exception->getMessage());
            throw new ApplicationException('Falha ao salvar informacoes no banco de dados.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->markUploadAsFailed($uploadId, $exception->getMessage());
            throw $exception instanceof ApplicationException
                ? $exception
                : new ApplicationException('Falha ao processar o arquivo: ' . $exception->getMessage());
        }
    }

    private function markUploadAsFailed(?int $uploadId, string $message): void
    {
        if ($uploadId === null) {
            return;
        }

        try {
            $uploadRepository = new UploadedFileRepository();
            $logRepository = new ProcessingLogRepository();

            $uploadRepository->updateProcessingResult($uploadId, 'failed', 'System', $message, ['database_error' => true]);
            $logRepository->create($uploadId, 'database', 'failed', $message);
        } catch (Throwable) {
        }
    }

    private function cleanupStoredFile(?string $path): void
    {
        if ($path === null || $path === '' || !is_file($path)) {
            return;
        }

        try {
            unlink($path);
        } catch (Throwable) {
        }
    }
}
