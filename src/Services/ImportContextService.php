<?php

declare(strict_types=1);

namespace App\Services;

final class ImportContextService
{
    public function fromRequest(array $request): array
    {
        return [
            'process_type' => $this->clean((string) ($request['process_type'] ?? '')),
            'cre_folder' => $this->clean((string) ($request['cre_folder'] ?? '')),
        ];
    }

    public function fromUpload(array $upload): array
    {
        $metadata = [];
        $json = (string) ($upload['metadata_json'] ?? '');

        if ($json !== '') {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $metadata = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $metadata = [];
            }
        }

        return is_array($metadata['import_context'] ?? null) ? $metadata['import_context'] : [];
    }

    public function applyToConversionResult(array $conversionResult, array $context): array
    {
        if (($conversionResult['normalized_data'] ?? null) === null) {
            return $conversionResult;
        }

        $normalized = $conversionResult['normalized_data'];
        $metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];
        $isPrefeituraRio = $this->isPrefeituraRio($metadata, $conversionResult, $context);

        if (!$isPrefeituraRio && $this->isEmptyContext($context)) {
            return $conversionResult;
        }

        $normalizedContext = $this->normalizeContext($context, $isPrefeituraRio);
        $metadata['import_context'] = $normalizedContext;

        if ($isPrefeituraRio) {
            $metadata['import_process'] = 'prefeitura_rio';
            $metadata['import_process_label'] = 'Prefeitura do Rio';
            unset($metadata['requires_contract_number'], $metadata['missing_contract_number']);
        }

        $normalized['metadata'] = $metadata;
        $normalized = $this->applyContextColumns($normalized, $normalizedContext, $isPrefeituraRio);
        $normalized['metadata'] = $this->withPresentationContext($normalized['metadata'], $normalizedContext, $isPrefeituraRio);

        $conversionResult['normalized_data'] = $normalized;
        $conversionResult['metadata'] = $normalized['metadata'];

        return $conversionResult;
    }

    public function applyManualValue(array $normalized, string $target, string $value): array
    {
        $metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];
        $context = is_array($metadata['import_context'] ?? null) ? $metadata['import_context'] : [];
        $isPrefeituraRio = $this->isPrefeituraRio($metadata, [], $context);

        if ($target === 'cre_folder') {
            $context['cre_folder'] = $this->clean($value);
        }

        unset($metadata['requires_contract_number'], $metadata['missing_contract_number']);

        $context = $this->normalizeContext($context, $isPrefeituraRio);
        $metadata['import_context'] = $context;
        $normalized['metadata'] = $metadata;
        $normalized = $this->applyContextColumns($normalized, $context, $isPrefeituraRio);
        $normalized['metadata'] = $this->withPresentationContext($normalized['metadata'], $context, $isPrefeituraRio);

        return $normalized;
    }

    private function applyContextColumns(array $normalized, array $context, bool $isPrefeituraRio): array
    {
        if (!$isPrefeituraRio) {
            return $normalized;
        }

        $columns = array_values(is_array($normalized['columns'] ?? null) ? $normalized['columns'] : []);
        $rows = array_values(is_array($normalized['rows'] ?? null) ? $normalized['rows'] : []);
        $metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];
        $labels = is_array($metadata['column_labels'] ?? null) ? $metadata['column_labels'] : [];

        $columns = array_values(array_filter($columns, static fn (string $column): bool => $column !== 'contrato'));

        if ($context['cre_folder'] !== '' && !in_array('cre_pasta', $columns, true)) {
            array_splice($columns, 2, 0, ['cre_pasta']);
        }

        unset($labels['contrato']);
        $labels['cre_pasta'] = 'CRE/Pasta';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            unset($row['contrato']);

            if ($context['cre_folder'] !== '') {
                $row['cre_pasta'] = $context['cre_folder'];
            } else {
                unset($row['cre_pasta']);
            }

            $rows[$index] = $row;
        }

        $metadata['column_labels'] = $labels;
        $metadata['total_rows'] = count($rows);
        $metadata['total_columns'] = count($columns);

        return [
            'columns' => $columns,
            'rows' => $rows,
            'metadata' => $metadata,
        ];
    }

    private function withPresentationContext(array $metadata, array $context, bool $isPrefeituraRio): array
    {
        if (!$isPrefeituraRio || !is_array($metadata['presentation'] ?? null)) {
            return $metadata;
        }

        $summary = is_array($metadata['presentation']['summary'] ?? null)
            ? $metadata['presentation']['summary']
            : [];

        $summary = array_values(array_filter(
            $summary,
            static function ($item): bool {
                return is_array($item) && !in_array((string) ($item['label'] ?? ''), ['Processo', 'Contrato', 'CRE/Pasta'], true);
            }
        ));

        array_unshift(
            $summary,
            ['label' => 'Processo', 'value' => 'Prefeitura do Rio']
        );

        if ($context['cre_folder'] !== '') {
            array_splice($summary, 1, 0, [['label' => 'CRE/Pasta', 'value' => $context['cre_folder']]]);
        }

        $metadata['presentation']['summary'] = $summary;

        return $metadata;
    }

    private function normalizeContext(array $context, bool $isPrefeituraRio): array
    {
        $processType = $this->clean((string) ($context['process_type'] ?? ''));
        if ($isPrefeituraRio) {
            $processType = 'prefeitura_rio';
        }

        return [
            'process_type' => $processType,
            'process_label' => $processType === 'prefeitura_rio' ? 'Prefeitura do Rio' : '',
            'cre_folder' => $this->clean((string) ($context['cre_folder'] ?? '')),
        ];
    }

    private function isPrefeituraRio(array $metadata, array $conversionResult, array $context): bool
    {
        return ($context['process_type'] ?? '') === 'prefeitura_rio'
            || ($metadata['source_layout'] ?? '') === 'prefeitura_rio_quantidades_por_data'
            || ($metadata['import_process'] ?? '') === 'prefeitura_rio'
            || ($conversionResult['converter'] ?? '') === 'FixedWidthScheduleConverter';
    }

    private function isEmptyContext(array $context): bool
    {
        return $this->clean((string) ($context['process_type'] ?? '')) === ''
            && $this->clean((string) ($context['cre_folder'] ?? '')) === '';
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?: '');
    }
}
