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
        if (!class_exists(\ZipArchive::class)) {
            throw new ApplicationException('Exportacao XLSX nao esta disponivel nesta instalacao porque a extensao zip do PHP nao esta habilitada.');
        }

        $originalExportName = pathinfo($upload['original_filename'], PATHINFO_FILENAME) . '.xlsx';
        $storedFilename = $this->randomExportFilename('xlsx');
        $path = $directory . DIRECTORY_SEPARATOR . $storedFilename;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new ApplicationException('Nao foi possivel criar o arquivo XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRelsXml());
        $zip->addFromString('docProps/app.xml', $this->xlsxAppPropertiesXml());
        $zip->addFromString('docProps/core.xml', $this->xlsxCorePropertiesXml());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->xlsxStylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxWorksheetXml($normalized));

        if (!$zip->close()) {
            throw new ApplicationException('Nao foi possivel finalizar o arquivo XLSX.');
        }

        return [
            'original_export_name' => $originalExportName,
            'stored_filename' => $storedFilename,
            'storage_path' => $path,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
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

    private function xlsxWorksheetXml(array $normalized): string
    {
        $columns = array_values($normalized['columns'] ?? []);
        $rows = array_values($normalized['rows'] ?? []);
        $sheetRows = [$columns];

        foreach ($rows as $row) {
            $sheetRow = [];
            foreach ($columns as $column) {
                $sheetRow[] = (string) ($row[$column] ?? '');
            }
            $sheetRows[] = $sheetRow;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheetData>';

        foreach ($sheetRows as $rowIndex => $rowValues) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="' . $excelRow . '">';

            foreach ($rowValues as $columnIndex => $value) {
                $cellReference = $this->xlsxColumnName($columnIndex + 1) . $excelRow;
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $xml .= '<c r="' . $cellReference . '" t="inlineStr"' . $style . '><is><t>' . $this->escapeXml((string) $value) . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';
        $xml .= '</worksheet>';

        return $xml;
    }

    private function xlsxColumnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function xlsxContentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function xlsxWorkbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Dados" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function xlsxStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function xlsxAppPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Conversor de Arquivos</Application>'
            . '</Properties>';
    }

    private function xlsxCorePropertiesXml(): string
    {
        $createdAt = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Conversor de Arquivos</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
