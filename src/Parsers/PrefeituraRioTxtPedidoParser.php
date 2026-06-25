<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Support\FixedWidthScheduleExtractor;
use DateTimeImmutable;

final class PrefeituraRioTxtPedidoParser
{
    private const CLIENTE = 'PREFEITURA_RIO';
    private const LAYOUT = 'prefeitura_rio_quantidades_por_data';

    public function parse(string $filePath): array
    {
        $result = $this->emptyResult($filePath);

        if (!is_file($filePath) || !is_readable($filePath)) {
            return $this->withError($result, 'Nao foi possivel ler o arquivo TXT informado.');
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            return $this->withError($result, 'O arquivo TXT esta vazio.');
        }

        $lines = preg_split('/\R/u', $content) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
        $fileSize = filesize($filePath);
        $baseMetadata = [
            'original_filename' => basename($filePath),
            'extension' => strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION)),
            'mime_type' => null,
            'detected_type' => 'txt',
            'file_size' => $fileSize === false ? null : $fileSize,
        ];

        $result['informacoes_complementares']['total_linhas_lidas'] = count($lines);
        $structured = (new FixedWidthScheduleExtractor())->extract($lines, $baseMetadata);

        if ($structured === null) {
            return $this->withError($result, 'Layout TXT da Prefeitura do Rio nao reconhecido.');
        }

        $metadata = is_array($structured['metadata'] ?? null) ? $structured['metadata'] : [];
        $rows = array_values(is_array($structured['rows'] ?? null) ? $structured['rows'] : []);
        $quantityColumns = $this->quantityColumns($metadata, $rows);
        $contract = $this->identifyContract($rows, $result['warnings']);
        $pedidos = $this->buildPedidos(
            $rows,
            $quantityColumns,
            basename($filePath),
            $contract,
            $result['erros'],
            $result['warnings']
        );

        $result['layout_valido'] = true;
        $result['contrato'] = $contract;
        $result['pedidos'] = $pedidos;
        $result['warnings'] = array_values(array_unique(array_merge(
            $result['warnings'],
            is_array($structured['warnings'] ?? null) ? $structured['warnings'] : []
        )));
        $result['informacoes_complementares'] = array_merge($result['informacoes_complementares'], [
            'layout' => self::LAYOUT,
            'linhas_processadas' => (int) ($metadata['parsed_rows'] ?? count($rows)),
            'linhas_ignoradas' => (int) ($metadata['unparsed_rows'] ?? 0),
            'total_pedidos' => count($pedidos),
            'total_itens' => array_sum(array_map(static fn (array $pedido): int => count($pedido['itens'] ?? []), $pedidos)),
            'datas_entrega' => array_values(array_unique(array_map(
                static fn (array $column): string => $column['data_entrega'],
                $quantityColumns
            ))),
        ]);

        if ($pedidos === []) {
            $result['erros'][] = 'Nenhum item com quantidade maior que zero foi encontrado.';
        } else {
            $firstPedido = $pedidos[0];
            $result['data_entrega'] = $firstPedido['data_entrega'];
            $result['codigo_cliente'] = $firstPedido['codigo_cliente'];
            $result['observacao'] = $firstPedido['observacao'];
            $result['itens'] = $firstPedido['itens'];
            $result['cabecalho'] = $firstPedido['cabecalho'];
        }

        $result['erros'] = array_values(array_unique($result['erros']));

        return $result;
    }

    private function emptyResult(string $filePath): array
    {
        return [
            'cliente' => self::CLIENTE,
            'arquivo_origem' => basename($filePath),
            'contrato' => '',
            'data_entrega' => '',
            'codigo_cliente' => '',
            'observacao' => '',
            'itens' => [],
            'cabecalho' => [],
            'informacoes_complementares' => [
                'layout' => self::LAYOUT,
                'cre' => '',
            ],
            'pedidos' => [],
            'layout_valido' => false,
            'erros' => [],
            'warnings' => [],
        ];
    }

    private function withError(array $result, string $error): array
    {
        $result['layout_valido'] = false;
        $result['erros'][] = $error;

        return $result;
    }

    private function buildPedidos(
        array $rows,
        array $quantityColumns,
        string $originalFilename,
        string $contract,
        array &$errors,
        array &$warnings
    ): array {
        $groups = [];
        $warnedUnit = false;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->validateRequiredRowFields($row, $index + 1, $errors);
            $pedidoOrigem = $this->normalizeCode((string) ($row['pedido'] ?? ''));
            $codigoCliente = $this->normalizeCode((string) ($row['unidade_codigo'] ?? ''));
            $groupKey = $pedidoOrigem . '|' . $codigoCliente;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = $this->newPedido($row, $originalFilename, $contract);
            }

            foreach ($quantityColumns as $columnIndex => $columnInfo) {
                $rawQuantity = (string) ($row[$columnInfo['column']] ?? '0,00');
                $quantity = $this->normalizeQuantity($rawQuantity);

                if ($quantity === null) {
                    $errors[] = 'Quantidade invalida na linha ' . ($index + 1) . ', coluna ' . $columnInfo['column'] . '.';
                    continue;
                }

                if ((float) $quantity <= 0.0) {
                    continue;
                }

                if (!$warnedUnit) {
                    $warnings[] = 'O layout TXT da Prefeitura do Rio nao informa unidade de medida do produto; o campo unidade permanece vazio para mapeamento futuro.';
                    $warnedUnit = true;
                }

                if ($groups[$groupKey]['data_entrega'] === '') {
                    $groups[$groupKey]['data_entrega'] = $columnInfo['data_entrega'];
                }

                $groups[$groupKey]['itens'][] = [
                    'codigo_produto' => $this->normalizeCode((string) ($row['produto_codigo'] ?? '')),
                    'quantidade' => $quantity,
                    'sequencia_entrega' => (string) ($columnIndex + 1),
                    'unidade' => '',
                    'data_entrega' => $columnInfo['data_entrega'],
                    'descricao_produto' => $this->clean((string) ($row['produto_nome'] ?? '')),
                    'pedido_origem' => $pedidoOrigem,
                    'pedido_item_origem' => $this->normalizeCode((string) ($row['pedido_item'] ?? '')),
                    'unidade_entrega_codigo' => $codigoCliente,
                    'unidade_entrega_nome' => $this->clean((string) ($row['unidade_nome'] ?? '')),
                    'documento_origem' => $this->clean((string) ($row['documento'] ?? '')),
                ];
            }
        }

        $pedidos = array_values(array_filter(
            $groups,
            static fn (array $pedido): bool => ($pedido['itens'] ?? []) !== []
        ));

        usort($pedidos, static function (array $left, array $right): int {
            return [$left['cabecalho']['numero_pedido'] ?? '', $left['codigo_cliente'] ?? '']
                <=> [$right['cabecalho']['numero_pedido'] ?? '', $right['codigo_cliente'] ?? ''];
        });

        return $pedidos;
    }

    private function newPedido(array $row, string $originalFilename, string $contract): array
    {
        $periodoInicio = $this->normalizeDate((string) ($row['periodo_inicio'] ?? '')) ?? '';
        $periodoFim = $this->normalizeDate((string) ($row['periodo_fim'] ?? '')) ?? '';
        $pedidoOrigem = $this->normalizeCode((string) ($row['pedido'] ?? ''));
        $pedidoItem = $this->normalizeCode((string) ($row['pedido_item'] ?? ''));
        $codigoCliente = $this->normalizeCode((string) ($row['unidade_codigo'] ?? ''));
        $unidadeNome = $this->clean((string) ($row['unidade_nome'] ?? ''));
        $documento = $this->clean((string) ($row['documento'] ?? ''));

        return [
            'cliente' => self::CLIENTE,
            'arquivo_origem' => $originalFilename,
            'contrato' => $contract,
            'data_entrega' => '',
            'codigo_cliente' => $codigoCliente,
            'observacao' => 'Pedido Prefeitura do Rio ' . $pedidoOrigem . ' - unidade ' . $codigoCliente . ' - documento ' . $documento,
            'itens' => [],
            'cabecalho' => [
                'numero_pedido' => $pedidoOrigem,
                'pedido_item' => $pedidoItem,
                'periodo_inicio' => $periodoInicio,
                'periodo_fim' => $periodoFim,
                'fornecedor_codigo' => $this->normalizeCode((string) ($row['fornecedor_codigo'] ?? '')),
                'fornecedor_nome' => $this->clean((string) ($row['fornecedor_nome'] ?? '')),
                'grupo' => $this->normalizeCode((string) ($row['grupo'] ?? '')),
                'unidade_codigo' => $codigoCliente,
                'unidade_nome' => $unidadeNome,
                'documento' => $documento,
            ],
            'informacoes_complementares' => [
                'layout' => self::LAYOUT,
                'controle' => $this->clean((string) ($row['controle'] ?? '')),
                'cre' => $this->identifyCre($row),
            ],
        ];
    }

    private function quantityColumns(array $metadata, array $rows): array
    {
        $presentation = is_array($metadata['presentation'] ?? null) ? $metadata['presentation'] : [];
        $columns = is_array($presentation['quantity_columns'] ?? null) ? $presentation['quantity_columns'] : [];
        $mapped = [];

        foreach ($columns as $item) {
            if (!is_array($item)) {
                continue;
            }

            $column = (string) ($item['column'] ?? '');
            $date = (string) ($item['date'] ?? '');
            $normalizedDate = $this->normalizeDate($date);

            if ($column !== '' && $normalizedDate !== null) {
                $mapped[] = ['column' => $column, 'data_entrega' => $normalizedDate];
            }
        }

        if ($mapped !== []) {
            return $mapped;
        }

        $firstRow = is_array($rows[0] ?? null) ? $rows[0] : [];
        foreach (array_keys($firstRow) as $column) {
            if (preg_match('/^qtd_(\d{2})_(\d{2})_(\d{4})$/', (string) $column, $matches) !== 1) {
                continue;
            }

            $mapped[] = [
                'column' => (string) $column,
                'data_entrega' => $matches[3] . '-' . $matches[2] . '-' . $matches[1],
            ];
        }

        return $mapped;
    }

    private function validateRequiredRowFields(array $row, int $lineNumber, array &$errors): void
    {
        $required = [
            'periodo_inicio' => 'periodo_inicio',
            'periodo_fim' => 'periodo_fim',
            'pedido' => 'pedido',
            'pedido_item' => 'pedido_item',
            'unidade_codigo' => 'codigo_cliente',
            'documento' => 'documento',
            'produto_codigo' => 'codigo_produto',
        ];

        foreach ($required as $field => $label) {
            if ($this->clean((string) ($row[$field] ?? '')) === '') {
                $errors[] = 'Campo obrigatorio ausente na linha ' . $lineNumber . ': ' . $label . '.';
            }
        }
    }

    private function identifyContract(array $rows, array &$warnings): string
    {
        $values = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = $this->clean((string) ($row['controle'] ?? ''));
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        $contracts = array_keys($values);

        if ($contracts === []) {
            $warnings[] = 'Contrato nao identificado no TXT.';

            return '';
        }

        if (count($contracts) > 1) {
            $warnings[] = 'Mais de um contrato/controle foi identificado no TXT; o primeiro foi usado no resumo.';
        }

        return $contracts[0];
    }

    private function identifyCre(array $row): string
    {
        foreach (['unidade_nome', 'documento', 'controle'] as $field) {
            $value = $this->clean((string) ($row[$field] ?? ''));
            if (preg_match('/\bCRE\s*[-\/]?\s*\d{1,2}\b/i', $value, $matches) === 1) {
                return strtoupper(preg_replace('/\s+/', ' ', $matches[0]) ?: $matches[0]);
            }
        }

        return '';
    }

    private function normalizeDate(string $value): ?string
    {
        $value = $this->clean($value);

        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }

        return null;
    }

    private function normalizeQuantity(string $value): ?string
    {
        $value = str_replace(' ', '', trim($value));

        if ($value === '') {
            return null;
        }

        if (strpos($value, ',') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeCode(string $value): string
    {
        return $this->clean($value);
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?: '');
    }
}
