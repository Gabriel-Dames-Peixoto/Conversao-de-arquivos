<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApplicationException;

final class ExportService
{
    /** @var array<string,mixed> */
    private $appConfig;

    public function __construct(array $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    public function export(array $upload, array $normalized, string $format): array
    {
        $supportedFormats = $this->appConfig['export_formats'];

        if (!in_array($format, $supportedFormats, true)) {
            throw new ApplicationException('Formato de exportacao nao suportado.');
        }

        $directory = $this->appConfig['storage']['exports'];
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ApplicationException('Nao foi possivel criar o diretorio de exportacao.');
        }

        switch ($format) {
            case 'csv':
                return $this->exportCsv($directory, $upload, $normalized);
            case 'json':
                return $this->exportJson($directory, $upload, $normalized);
            case 'xml':
                return $this->exportXml($directory, $upload, $normalized);
            case 'xlsx':
                return $this->exportXlsx($directory, $upload, $normalized);
            default:
                throw new ApplicationException('Formato de exportacao nao implementado.');
        }
    }

    public static function isFormatAvailable(string $format): bool
    {
        switch ($format) {
            case 'csv':
            case 'json':
            case 'xml':
                return true;
            case 'xlsx':
                return class_exists(\ZipArchive::class);
            default:
                return false;
        }
    }

    private function exportCsv(string $directory, array $upload, array $normalized): array
    {
        $originalExportName = pathinfo($upload['original_filename'], PATHINFO_FILENAME) . '.csv';
        $storedFilename = $this->randomExportFilename('csv');
        $path = $directory . DIRECTORY_SEPARATOR . $storedFilename;
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new ApplicationException('Nao foi possivel criar o arquivo CSV.');
        }

        fputcsv($handle, $normalized['columns']);
        foreach ($normalized['rows'] as $row) {
            $values = [];
            foreach ($normalized['columns'] as $column) {
                $values[] = $row[$column] ?? '';
            }
            fputcsv($handle, $values);
        }
        fclose($handle);

        return [
            'original_export_name' => $originalExportName,
            'stored_filename' => $storedFilename,
            'storage_path' => $path,
            'mime_type' => 'text/csv',
        ];
    }

    private function exportJson(string $directory, array $upload, array $normalized): array
    {
        $originalExportName = pathinfo($upload['original_filename'], PATHINFO_FILENAME) . '.json';
        $storedFilename = $this->randomExportFilename('json');
        $path = $directory . DIRECTORY_SEPARATOR . $storedFilename;

        $payload = json_encode([
            'columns' => $normalized['columns'],
            'rows' => $normalized['rows'],
            'metadata' => $normalized['metadata'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($payload === false || file_put_contents($path, $payload) === false) {
            throw new ApplicationException('Nao foi possivel criar o arquivo JSON.');
        }

        return [
            'original_export_name' => $originalExportName,
            'stored_filename' => $storedFilename,
            'storage_path' => $path,
            'mime_type' => 'application/json',
        ];
    }

    private function exportXml(string $directory, array $upload, array $normalized): array
    {
        $originalExportName = pathinfo($upload['original_filename'], PATHINFO_FILENAME) . '.xml';
        $storedFilename = $this->randomExportFilename('xml');
        $path = $directory . DIRECTORY_SEPARATOR . $storedFilename;

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);
        $xml->startElement('dataset');
        $xml->startElement('metadata');

        foreach ($normalized['metadata'] as $key => $value) {
            $encodedValue = '';
            if (is_scalar($value)) {
                $encodedValue = (string) $value;
            } else {
                $encodedValue = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
            }

            $xml->writeElement((string) $key, $encodedValue);
        }

        $xml->endElement();
        $xml->startElement('rows');

        foreach ($normalized['rows'] as $row) {
            $xml->startElement('row');
            foreach ($normalized['columns'] as $column) {
                $xml->writeElement($this->sanitizeXmlTag((string) $column), (string) ($row[$column] ?? ''));
            }
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        if (file_put_contents($path, $xml->outputMemory()) === false) {
            throw new ApplicationException('Nao foi possivel criar o arquivo XML.');
        }

        return [
            'original_export_name' => $originalExportName,
            'stored_filename' => $storedFilename,
            'storage_path' => $path,
            'mime_type' => 'application/xml',
        ];
    }

    private function exportXlsx(string $directory, array $upload, array $normalized): array
    {
        throw new ApplicationException('Exportacao XLSX nao esta disponivel nesta instalacao porque a extensao zip do PHP nao esta habilitada.');
    }

    private function randomExportFilename(string $extension): string
    {
        return sprintf('export_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(6)), $extension);
    }

    private function sanitizeXmlTag(string $tag): string
    {
        $tag = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $tag) ?: 'column';

        if (preg_match('/^[0-9]/', $tag) === 1) {
            $tag = 'col_' . $tag;
        }

        return $tag;
    }
}
