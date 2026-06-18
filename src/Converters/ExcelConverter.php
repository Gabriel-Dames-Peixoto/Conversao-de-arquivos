<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;

final class ExcelConverter extends AbstractConverter implements ConverterInterface
{
    public function supports(array $detection): bool
    {
        return in_array($detection['detected_type'], ['xls', 'xlsx'], true);
    }

    public function getName(): string
    {
        return 'ExcelConverter';
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        $this->ensureReadableAndNotEmpty($filePath, 'Excel');

        $warnings = [];

        if ($detection['detected_type'] === 'xls') {
            $metadata = array_merge(
                $this->baseMetadata($detection, $originalFilename),
                ['conversion_issue' => 'Formato XLS binario legado sem parser disponivel.']
            );

            return [
                'status' => 'processed',
                'converter' => $this->getName(),
                'message' => 'Arquivo XLS registrado com diagnostico; leitura binaria antiga ainda nao esta implementada.',
                'warnings' => ['O formato XLS foi registrado, mas exige um parser especifico para conversao completa.'],
                'error' => null,
                'metadata' => $metadata,
                'normalized_data' => $this->diagnosticData($metadata, 'Formato XLS binario legado sem parser disponivel.'),
            ];
        }

        if (!class_exists(\ZipArchive::class)) {
            $metadata = array_merge(
                $this->baseMetadata($detection, $originalFilename),
                ['conversion_issue' => 'Extensao ZipArchive indisponivel para leitura XLSX.']
            );

            return [
                'status' => 'processed',
                'converter' => $this->getName(),
                'message' => 'Arquivo XLSX registrado com diagnostico; ZipArchive nao esta disponivel para leitura.',
                'warnings' => ['Habilite a extensao zip do PHP ou adicione uma biblioteca de planilhas para conversao XLSX.'],
                'error' => null,
                'metadata' => $metadata,
                'normalized_data' => $this->diagnosticData($metadata, 'Extensao ZipArchive indisponivel para leitura XLSX.'),
            ];
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            $metadata = array_merge(
                $this->baseMetadata($detection, $originalFilename),
                ['conversion_issue' => 'Arquivo XLSX corrompido ou ilegivel.']
            );

            return [
                'status' => 'processed',
                'converter' => $this->getName(),
                'message' => 'XLSX recebido e diagnosticado, mas nao foi possivel abrir o pacote.',
                'warnings' => ['O arquivo parece corrompido ou esta fora do padrao XLSX esperado.'],
                'error' => null,
                'metadata' => $metadata,
                'normalized_data' => $this->diagnosticData($metadata, 'Arquivo XLSX corrompido ou ilegivel.'),
            ];
        }

        $sharedStrings = $this->extractSharedStrings($zip);
        $worksheets = $this->listWorksheets($zip);

        if ($worksheets === []) {
            $zip->close();
            $metadata = array_merge(
                $this->baseMetadata($detection, $originalFilename),
                ['conversion_issue' => 'Nenhuma planilha encontrada no pacote XLSX.']
            );

            return [
                'status' => 'processed',
                'converter' => $this->getName(),
                'message' => 'XLSX recebido e diagnosticado, mas nenhuma planilha foi encontrada.',
                'warnings' => ['Apenas o arquivo foi registrado.'],
                'error' => null,
                'metadata' => $metadata,
                'normalized_data' => $this->diagnosticData($metadata, 'Nenhuma planilha encontrada no pacote XLSX.'),
            ];
        }

        $rows = [];

        foreach ($worksheets as $worksheet) {
            $sheetXml = $zip->getFromName($worksheet['path']);
            if (!is_string($sheetXml)) {
                $warnings[] = 'A aba ' . $worksheet['name'] . ' nao foi encontrada dentro do XLSX.';
                continue;
            }

            foreach ($this->extractWorksheetCells($sheetXml, $sharedStrings) as $cellRow) {
                $rows[] = array_merge(['aba' => $worksheet['name']], $cellRow);
            }
        }

        $zip->close();

        if ($rows === []) {
            $warnings[] = 'A planilha nao possui dados legiveis.';
        }

        $headers = ['aba', 'celula', 'linha', 'coluna', 'valor'];

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => $rows === [] ? 'XLSX processado, mas sem celulas legiveis.' : 'XLSX importado com sucesso.',
            'warnings' => $warnings,
            'error' => null,
            'metadata' => array_merge(
                $this->baseMetadata($detection, $originalFilename),
                ['worksheets' => array_column($worksheets, 'name')]
            ),
            'normalized_data' => $this->buildNormalizedData(
                $headers,
                $rows,
                array_merge(
                    $this->baseMetadata($detection, $originalFilename),
                    ['worksheets' => array_column($worksheets, 'name')]
                )
            ),
        ];
    }

    private function listWorksheets(\ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if (!is_string($workbookXml)) {
            return [];
        }

        $workbook = simplexml_load_string($workbookXml);
        if ($workbook === false) {
            return [];
        }

        $relationships = $this->workbookRelationships($zip);
        $namespace = $this->spreadsheetNamespace($workbook);
        $workbook->registerXPathNamespace('main', $namespace);
        $sheets = [];

        foreach ($workbook->xpath('//main:sheet') ?: [] as $sheet) {
            $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
            $target = $relationships[$relationshipId] ?? '';

            if ($target === '') {
                continue;
            }

            $sheets[] = [
                'name' => (string) ($sheet['name'] ?? 'Planilha'),
                'path' => $this->normalizeWorkbookTarget($target),
            ];
        }

        return $sheets;
    }

    private function diagnosticData(array $metadata, string $message): array
    {
        return $this->buildNormalizedData(
            ['campo', 'valor'],
            [
                ['campo' => 'arquivo', 'valor' => $metadata['original_filename'] ?? ''],
                ['campo' => 'tipo_detectado', 'valor' => $metadata['detected_type'] ?? 'xlsx'],
                ['campo' => 'diagnostico', 'valor' => $message],
            ],
            $metadata
        );
    }

    private function workbookRelationships(\ZipArchive $zip): array
    {
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($relationshipsXml)) {
            return [];
        }

        $relationships = simplexml_load_string($relationshipsXml);
        if ($relationships === false) {
            return [];
        }

        $namespace = $this->relationshipNamespace($relationships);
        $relationships->registerXPathNamespace('rel', $namespace);
        $targets = [];

        foreach ($relationships->xpath('//rel:Relationship') ?: [] as $relationship) {
            $targets[(string) ($relationship['Id'] ?? '')] = (string) ($relationship['Target'] ?? '');
        }

        return $targets;
    }

    private function normalizeWorkbookTarget(string $target): string
    {
        $target = ltrim($target, '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    private function extractSharedStrings(\ZipArchive $zip): array
    {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($sharedXml)) {
            return [];
        }

        $shared = simplexml_load_string($sharedXml);
        if ($shared === false) {
            return [];
        }

        $namespace = $this->spreadsheetNamespace($shared);
        $shared->registerXPathNamespace('main', $namespace);
        $strings = [];

        foreach ($shared->xpath('//main:si') ?: [] as $item) {
            $item->registerXPathNamespace('main', $namespace);
            $parts = [];

            foreach ($item->xpath('.//main:t') ?: [] as $textNode) {
                $parts[] = (string) $textNode;
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function extractWorksheetCells(string $sheetXml, array $sharedStrings): array
    {
        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            return [];
        }

        $namespace = $this->spreadsheetNamespace($sheet);
        $sheet->registerXPathNamespace('main', $namespace);
        $rows = [];

        foreach ($sheet->xpath('//main:sheetData/main:row') ?: [] as $rowNode) {
            $rowNumber = (string) ($rowNode['r'] ?? '');
            $cellPosition = 0;

            foreach ($rowNode->children($namespace)->c as $cell) {
                $cellPosition++;
                $cellReference = (string) ($cell['r'] ?? '');

                if ($cellReference === '') {
                    $cellReference = $this->columnNameFromNumber($cellPosition) . $rowNumber;
                }

                $column = preg_replace('/\d+/', '', $cellReference) ?: '';
                $value = trim($this->extractCellValue($cell, $sharedStrings, $namespace));

                if ($value === '') {
                    continue;
                }

                $rows[] = [
                    'celula' => $cellReference,
                    'linha' => $rowNumber !== '' ? $rowNumber : preg_replace('/\D+/', '', $cellReference),
                    'coluna' => $column,
                    'valor' => $value,
                ];
            }
        }

        return $rows;
    }

    private function columnNameFromNumber(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function extractCellValue(\SimpleXMLElement $cell, array $sharedStrings, string $namespace): string
    {
        $type = (string) ($cell['t'] ?? '');
        $children = $cell->children($namespace);

        if ($type === 's') {
            $index = (int) ($children->v ?? 0);

            return (string) ($sharedStrings[$index] ?? '');
        }

        if ($type === 'inlineStr') {
            $cell->registerXPathNamespace('main', $namespace);
            $parts = [];

            foreach ($cell->xpath('.//main:t') ?: [] as $textNode) {
                $parts[] = (string) $textNode;
            }

            return implode('', $parts);
        }

        return (string) ($children->v ?? '');
    }

    private function spreadsheetNamespace(\SimpleXMLElement $xml): string
    {
        $namespaces = $xml->getNamespaces(true);

        return $namespaces[''] ?? $namespaces['x'] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    }

    private function relationshipNamespace(\SimpleXMLElement $xml): string
    {
        $namespaces = $xml->getNamespaces(true);

        return $namespaces[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships';
    }
}
