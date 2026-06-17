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
            return [
                'status' => 'unsupported',
                'converter' => $this->getName(),
                'message' => 'Arquivo XLS aceito, mas a leitura binaria antiga nao esta implementada nesta instalacao.',
                'warnings' => ['O formato XLS foi registrado, mas exige um parser especifico para conversao completa.'],
                'error' => null,
                'metadata' => $this->baseMetadata($detection, $originalFilename),
                'normalized_data' => null,
            ];
        }

        if (!class_exists(\ZipArchive::class)) {
            return [
                'status' => 'unsupported',
                'converter' => $this->getName(),
                'message' => 'Arquivo XLSX aceito, mas a extensao ZipArchive nao esta disponivel para leitura.',
                'warnings' => ['Habilite a extensao zip do PHP ou adicione uma biblioteca de planilhas para conversao XLSX.'],
                'error' => null,
                'metadata' => $this->baseMetadata($detection, $originalFilename),
                'normalized_data' => null,
            ];
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [
                'status' => 'failed',
                'converter' => $this->getName(),
                'message' => 'Nao foi possivel abrir o XLSX para leitura.',
                'warnings' => ['O arquivo parece corrompido ou esta fora do padrao XLSX esperado.'],
                'error' => 'Arquivo XLSX corrompido ou ilegivel.',
                'metadata' => $this->baseMetadata($detection, $originalFilename),
                'normalized_data' => null,
            ];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml)) {
            $shared = simplexml_load_string($sharedXml);
            if ($shared !== false) {
                foreach ($shared->si as $item) {
                    $sharedStrings[] = isset($item->t) ? (string) $item->t : trim((string) $item);
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!is_string($sheetXml)) {
            return [
                'status' => 'unsupported',
                'converter' => $this->getName(),
                'message' => 'XLSX aceito, mas a planilha principal nao foi encontrada.',
                'warnings' => ['Apenas o arquivo foi registrado.'],
                'error' => null,
                'metadata' => $this->baseMetadata($detection, $originalFilename),
                'normalized_data' => null,
            ];
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            return [
                'status' => 'unsupported',
                'converter' => $this->getName(),
                'message' => 'XLSX aceito, mas nao foi possivel interpretar o XML da planilha.',
                'warnings' => ['Apenas o arquivo foi registrado.'],
                'error' => null,
                'metadata' => $this->baseMetadata($detection, $originalFilename),
                'normalized_data' => null,
            ];
        }

        $namespaces = $sheet->getNamespaces(true);
        if (isset($namespaces[''])) {
            $sheet->registerXPathNamespace('main', $namespaces['']);
        }

        $cells = $sheet->xpath('//main:sheetData/main:row') ?: [];
        $rows = [];

        foreach ($cells as $rowNode) {
            $row = [];
            foreach ($rowNode->c as $cell) {
                $value = (string) ($cell->v ?? '');
                $type = (string) ($cell['t'] ?? '');
                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                }
                $row[] = $value;
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            $warnings[] = 'A planilha nao possui dados legiveis.';
        }

        $headers = array_map(
            static fn (string $header, int $index): string => trim($header) !== '' ? trim($header) : 'column_' . ($index + 1),
            $rows[0] ?? ['column_1'],
            array_keys($rows[0] ?? ['column_1'])
        );

        $normalizedRows = [];
        foreach (array_slice($rows, 1) as $rowData) {
            $normalizedRow = [];
            foreach ($headers as $index => $header) {
                $normalizedRow[$header] = $rowData[$index] ?? '';
            }
            $normalizedRows[] = $normalizedRow;
        }

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => 'XLSX importado com sucesso.',
            'warnings' => $warnings,
            'error' => null,
            'metadata' => $this->baseMetadata($detection, $originalFilename),
            'normalized_data' => $this->buildNormalizedData($headers, $normalizedRows, $this->baseMetadata($detection, $originalFilename)),
        ];
    }
}
