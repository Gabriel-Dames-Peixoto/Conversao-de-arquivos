<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ConverterInterface;
use App\Converters\CsvConverter;
use App\Converters\ExcelConverter;
use App\Converters\ImageConverter;
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
            new ImageConverter(),
            new PdfConverter(),
            new UnsupportedFileConverter(),
        ];
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        if (($detection['is_empty'] ?? false) === true) {
            $metadata = [
                'original_filename' => $originalFilename,
                'extension' => $detection['extension'] ?? '',
                'mime_type' => $detection['mime_type'] ?? '',
                'detected_type' => $detection['detected_type'] ?? 'unknown',
                'file_size' => $detection['file_size'] ?? 0,
                'conversion_issue' => 'Arquivo vazio.',
            ];

            return [
                'status' => 'processed',
                'converter' => 'System',
                'message' => 'Arquivo vazio recebido e diagnosticado.',
                'warnings' => ['Arquivo vazio. Nenhum conteudo foi convertido.'],
                'error' => null,
                'metadata' => $metadata,
                'normalized_data' => $this->diagnosticNormalizedData($metadata, 'Arquivo vazio.'),
            ];
        }

        foreach ($this->converters as $converter) {
            if ($converter->supports($detection)) {
                try {
                    return $converter->convert($filePath, $detection, $originalFilename);
                } catch (\Throwable $exception) {
                    $metadata = [
                        'original_filename' => $originalFilename,
                        'extension' => $detection['extension'] ?? '',
                        'mime_type' => $detection['mime_type'] ?? '',
                        'detected_type' => $detection['detected_type'] ?? 'unknown',
                        'file_size' => $detection['file_size'] ?? 0,
                        'conversion_issue' => $exception->getMessage(),
                    ];

                    return [
                        'status' => 'processed',
                        'converter' => $converter->getName(),
                        'message' => 'Arquivo recebido e diagnosticado, mas o conteudo nao pode ser convertido automaticamente.',
                        'warnings' => ['Falha de conversao registrada: ' . $exception->getMessage()],
                        'error' => null,
                        'metadata' => $metadata,
                        'normalized_data' => $this->diagnosticNormalizedData($metadata, $exception->getMessage()),
                    ];
                }
            }
        }

        return (new UnsupportedFileConverter())->convert($filePath, $detection, $originalFilename);
    }

    private function diagnosticNormalizedData(array $metadata, string $message): array
    {
        return [
            'columns' => ['campo', 'valor'],
            'rows' => [
                ['campo' => 'arquivo', 'valor' => (string) ($metadata['original_filename'] ?? '')],
                ['campo' => 'tipo_detectado', 'valor' => (string) ($metadata['detected_type'] ?? 'unknown')],
                ['campo' => 'diagnostico', 'valor' => $message],
            ],
            'metadata' => array_merge($metadata, [
                'total_rows' => 3,
                'total_columns' => 2,
                'processed_at' => date('Y-m-d H:i:s'),
            ]),
        ];
    }
}
