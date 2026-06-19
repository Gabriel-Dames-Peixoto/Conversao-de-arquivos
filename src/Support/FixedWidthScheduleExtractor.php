<?php

declare(strict_types=1);

namespace App\Support;

final class FixedWidthScheduleExtractor
{
    public function extract(array $lines, array $baseMetadata): ?array
    {
        $parsedRows = [];
        $dateColumns = [];
        $failedLines = 0;

        foreach ($lines as $line) {
            $parsed = $this->parseLine((string) $line);
            if ($parsed === null) {
                $failedLines++;
                continue;
            }

            foreach ($parsed['quantities'] as $date => $quantity) {
                $column = $this->dateColumn($date);
                if (!isset($dateColumns[$column])) {
                    $dateColumns[$column] = $date;
                }
            }

            $parsedRows[] = $parsed;
        }

        if ($parsedRows === []) {
            return null;
        }

        $usableRatio = count($parsedRows) / max(1, count($lines));
        if ($usableRatio < 0.6) {
            return null;
        }

        $columns = array_merge(
            [
                'periodo_inicio',
                'periodo_fim',
                'fornecedor_codigo',
                'fornecedor_nome',
                'pedido',
                'pedido_item',
                'grupo',
                'unidade_codigo',
                'unidade_nome',
                'documento',
                'produto_codigo',
                'produto_nome',
            ],
            array_keys($dateColumns),
            ['controle']
        );

        $rows = [];
        foreach ($parsedRows as $parsed) {
            $row = [
                'periodo_inicio' => $parsed['periodo_inicio'],
                'periodo_fim' => $parsed['periodo_fim'],
                'fornecedor_codigo' => $parsed['fornecedor_codigo'],
                'fornecedor_nome' => $parsed['fornecedor_nome'],
                'pedido' => $parsed['pedido'],
                'pedido_item' => $parsed['pedido_item'],
                'grupo' => $parsed['grupo'],
                'unidade_codigo' => $parsed['unidade_codigo'],
                'unidade_nome' => $parsed['unidade_nome'],
                'documento' => $parsed['documento'],
                'produto_codigo' => $parsed['produto_codigo'],
                'produto_nome' => $parsed['produto_nome'],
            ];

            foreach ($dateColumns as $column => $date) {
                $row[$column] = $parsed['quantities'][$date] ?? '0,00';
            }

            $row['controle'] = $parsed['controle'];
            $rows[] = $row;
        }

        $metadata = array_merge($baseMetadata, [
            'extraction_mode' => 'fixed_width_schedule',
            'source_layout' => 'prefeitura_rio_quantidades_por_data',
            'import_process' => 'prefeitura_rio',
            'import_process_label' => 'Prefeitura do Rio',
            'parsed_rows' => count($rows),
            'unparsed_rows' => $failedLines,
            'column_labels' => $this->columnLabels($dateColumns),
            'presentation' => $this->presentation($rows[0], $dateColumns),
        ]);

        $warnings = [];
        if ($failedLines > 0) {
            $warnings[] = $failedLines . ' linha(s) nao seguiram o layout fixo esperado e foram ignoradas.';
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'metadata' => $metadata,
            'warnings' => $warnings,
        ];
    }

    private function parseLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            return null;
        }

        if (preg_match_all('/(\d{2}\/\d{2}\/\d{4})\s+(\d{5},\d{2})/', $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        if (count($matches[0]) < 2) {
            return null;
        }

        $firstDateOffset = (int) $matches[0][0][1];
        $prefix = substr($line, 0, $firstDateOffset);
        $tailOffset = (int) $matches[0][count($matches[0]) - 1][1] + strlen((string) $matches[0][count($matches[0]) - 1][0]);
        $tail = trim(substr($line, $tailOffset));

        $pattern = '/^(?<registro>\d{4})(?<periodo_inicio>\d{2}\/\d{2}\/\d{4}) A (?<periodo_fim>\d{2}\/\d{2}\/\d{4})(?<fornecedor_codigo>\d{4})(?<fornecedor_nome>.+?)\s+(?<pedido>\d{8})(?<pedido_item>\d{2})\s+(?<grupo>\d{2})\s+(?<unidade_codigo>\d{3})\s+(?<unidade_nome>.+?)(?<documento>\d{6}\.\d{4})(?<produto_codigo>\d{11})(?<produto_nome>.+)$/';

        if (preg_match($pattern, $prefix, $parts) !== 1) {
            return null;
        }

        $quantities = [];
        foreach ($matches[1] as $index => $dateMatch) {
            $date = (string) $dateMatch[0];
            $quantity = (string) ($matches[2][$index][0] ?? '0,00');
            $quantities[$date] = $this->normalizeQuantity($quantity);
        }

        return [
            'registro' => trim((string) $parts['registro']),
            'periodo_inicio' => trim((string) $parts['periodo_inicio']),
            'periodo_fim' => trim((string) $parts['periodo_fim']),
            'fornecedor_codigo' => trim((string) $parts['fornecedor_codigo']),
            'fornecedor_nome' => trim((string) $parts['fornecedor_nome']),
            'pedido' => trim((string) $parts['pedido']),
            'pedido_item' => trim((string) $parts['pedido_item']),
            'grupo' => trim((string) $parts['grupo']),
            'unidade_codigo' => trim((string) $parts['unidade_codigo']),
            'unidade_nome' => trim((string) $parts['unidade_nome']),
            'documento' => trim((string) $parts['documento']),
            'produto_codigo' => trim((string) $parts['produto_codigo']),
            'produto_nome' => trim((string) $parts['produto_nome']),
            'quantities' => $quantities,
            'controle' => $tail,
        ];
    }

    private function dateColumn(string $date): string
    {
        return 'qtd_' . str_replace('/', '_', $date);
    }

    private function normalizeQuantity(string $quantity): string
    {
        $quantity = trim($quantity);
        [$integer, $decimal] = array_pad(explode(',', $quantity, 2), 2, '00');
        $integer = ltrim($integer, '0');

        return ($integer === '' ? '0' : $integer) . ',' . str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private function columnLabels(array $dateColumns): array
    {
        $labels = [
            'periodo_inicio' => 'Periodo inicio',
            'periodo_fim' => 'Periodo fim',
            'fornecedor_codigo' => 'Cod. fornecedor',
            'fornecedor_nome' => 'Fornecedor',
            'pedido' => 'Pedido',
            'pedido_item' => 'Item pedido',
            'grupo' => 'Grupo',
            'unidade_codigo' => 'Cod. unidade',
            'unidade_nome' => 'Unidade',
            'documento' => 'Documento',
            'produto_codigo' => 'Cod. produto',
            'produto_nome' => 'Produto',
            'controle' => 'Controle',
        ];

        foreach ($dateColumns as $column => $date) {
            $labels[$column] = 'Qtd. ' . $date;
        }

        return $labels;
    }

    private function presentation(array $firstRow, array $dateColumns): array
    {
        return [
            'type' => 'schedule_by_product',
            'title' => 'Itens e quantidades por data',
            'summary' => [
                ['label' => 'Periodo', 'value' => $firstRow['periodo_inicio'] . ' a ' . $firstRow['periodo_fim']],
                ['label' => 'Fornecedor', 'value' => $firstRow['fornecedor_codigo'] . ' - ' . $firstRow['fornecedor_nome']],
                ['label' => 'Pedido', 'value' => $firstRow['pedido'] . ' / item ' . $firstRow['pedido_item']],
                ['label' => 'Unidade', 'value' => $firstRow['unidade_codigo'] . ' - ' . $firstRow['unidade_nome']],
                ['label' => 'Documento', 'value' => $firstRow['documento']],
            ],
            'quantity_columns' => array_map(
                static fn (string $column, string $date): array => ['column' => $column, 'date' => $date],
                array_keys($dateColumns),
                array_values($dateColumns)
            ),
        ];
    }
}
