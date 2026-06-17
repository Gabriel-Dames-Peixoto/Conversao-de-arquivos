<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ConverterInterface;
use App\Converters\CsvConverter;
use App\Converters\ExcelConverter;
use App\Converters\JsonConverter;
use App\Converters\PdfConverter;
use App\Converters\TxtConverter;
use App\Converters\UnsupportedFileConverter;
use App\Converters\XmlConverter;

final class FileConversionService
{
    /** @var ConverterInterface[] */
    private array $converters;

    public function __construct()
    {
        $this->converters = [
            new CsvConverter(),
            new TxtConverter(),
            new JsonConverter(),
            new XmlConverter(),
            new ExcelConverter(),
            new PdfConverter(),
            new UnsupportedFileConverter(),
        ];
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        if (($detection['is_empty'] ?? false) === true) {
            return [
                'status' => 'failed',
                'converter' => 'System',
                'message' => 'O arquivo enviado esta vazio.',
                'warnings' => [],
                'error' => 'Arquivo vazio.',
                'metadata' => [
                    'original_filename' => $originalFilename,
                    'extension' => $detection['extension'] ?? '',
                    'mime_type' => $detection['mime_type'] ?? '',
                    'detected_type' => $detection['detected_type'] ?? 'unknown',
                    'file_size' => $detection['file_size'] ?? 0,
                ],
                'normalized_data' => null,
            ];
        }

        foreach ($this->converters as $converter) {
            if ($converter->supports($detection)) {
                try {
                    return $converter->convert($filePath, $detection, $originalFilename);
                } catch (\Throwable $exception) {
                    return [
                        'status' => 'failed',
                        'converter' => $converter->getName(),
                        'message' => 'Falha ao converter o arquivo.',
                        'warnings' => [],
                        'error' => $exception->getMessage(),
                        'metadata' => [
                            'original_filename' => $originalFilename,
                            'extension' => $detection['extension'] ?? '',
                            'mime_type' => $detection['mime_type'] ?? '',
                            'detected_type' => $detection['detected_type'] ?? 'unknown',
                            'file_size' => $detection['file_size'] ?? 0,
                        ],
                        'normalized_data' => null,
                    ];
                }
            }
        }

        return (new UnsupportedFileConverter())->convert($filePath, $detection, $originalFilename);
    }
}
