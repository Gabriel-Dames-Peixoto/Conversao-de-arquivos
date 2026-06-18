<?php

declare(strict_types=1);

namespace App\Services;

final class FileDetectionService
{
    public function detect(string $filePath, string $originalFilename): array
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $mimeType = $this->detectMimeType($filePath);
        $signature = $this->detectSignature($filePath);
        $fileSize = is_file($filePath) ? (filesize($filePath) ?: 0) : 0;
        $detectedType = $this->resolveDetectedType($extension, $mimeType, $signature);

        return [
            'extension' => $extension,
            'mime_type' => $mimeType,
            'detected_type' => $detectedType,
            'signature' => $signature,
            'file_size' => $fileSize,
            'is_empty' => $fileSize === 0,
        ];
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($filePath);
    }

    private function detectSignature(string $filePath): string
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return 'unknown';
        }

        $bytes = fread($handle, 8);
        fclose($handle);

        $hex = strtoupper(bin2hex((string) $bytes));

        if (strpos($hex, '25504446') === 0) {
            return 'pdf';
        }

        if (strpos($hex, '504B0304') === 0) {
            return 'zip';
        }

        if (strpos($hex, 'D0CF11E0') === 0) {
            return 'ole';
        }

        return 'unknown';
    }

    private function resolveDetectedType(string $extension, string $mimeType, string $signature): string
    {
        if ($signature === 'pdf' || $extension === 'pdf') {
            return 'pdf';
        }

        if (in_array($extension, ['csv'], true) || str_contains($mimeType, 'csv')) {
            return 'csv';
        }

        if (in_array($extension, ['json'], true) || str_contains($mimeType, 'json')) {
            return 'json';
        }

        if ($extension === 'xlsx' || ($signature === 'zip' && str_contains($mimeType, 'officedocument'))) {
            return 'xlsx';
        }

        if ($extension === 'xls' || $signature === 'ole') {
            return 'xls';
        }

        if (in_array($extension, ['xml'], true) || str_contains($mimeType, 'xml')) {
            return 'xml';
        }

        if (in_array($extension, ['html', 'htm'], true) || str_contains($mimeType, 'text/html')) {
            return 'unsupported';
        }

        if (in_array($extension, ['txt', 'log'], true) || str_starts_with($mimeType, 'text/')) {
            return 'txt';
        }

        return 'unsupported';
    }
}
