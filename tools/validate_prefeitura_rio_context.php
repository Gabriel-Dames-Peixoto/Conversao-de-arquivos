<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Autoloader.php';

App\Support\Autoloader::register(__DIR__ . '/../src');

$file = __DIR__ . '/../storage/uploads/20260619135554_85775abf1a3a2f20.txt';

if (!is_file($file)) {
    echo 'PREFEITURA_RIO_CONTEXT_SKIPPED fixture_not_found', PHP_EOL;
    exit(0);
}

$detection = (new App\Services\FileDetectionService())->detect($file, 'Pref. Rio.TXT');
$conversion = (new App\Services\FileConversionService())->convert($file, $detection, 'Pref. Rio.TXT');
$contextService = new App\Services\ImportContextService();
$analyzer = new App\Support\ReadabilityAnalyzer();
$failures = [];

$withoutContract = $contextService->applyToConversionResult($conversion, ['process_type' => 'prefeitura_rio']);
$review = $analyzer->analyze($withoutContract['normalized_data'], $withoutContract);
$columnsWithoutContract = $withoutContract['normalized_data']['columns'] ?? [];
$metadataWithoutContract = $withoutContract['normalized_data']['metadata'] ?? [];

assertCondition(($review['required'] ?? true) === false, 'Prefeitura do Rio sem contrato nao deveria exigir revisao manual.', $failures);
assertCondition(!in_array('contrato', $columnsWithoutContract, true), 'Coluna contrato nao deveria ser criada.', $failures);
assertCondition(($metadataWithoutContract['requires_contract_number'] ?? false) === false, 'Contrato nao deveria ser obrigatorio nos metadados.', $failures);

$exportGuard = new ReflectionMethod(App\Services\ExportProcessingService::class, 'ensureManualReviewCompleted');
$exportGuard->setAccessible(true);
$exportGuard->invoke(new App\Services\ExportProcessingService(['export_formats' => ['csv']]), $withoutContract['normalized_data']);

$withCre = $contextService->applyToConversionResult($conversion, [
    'process_type' => 'prefeitura_rio',
    'cre_folder' => 'CRE 01',
]);
$reviewWithCre = $analyzer->analyze($withCre['normalized_data'], $withCre);
$columns = $withCre['normalized_data']['columns'] ?? [];
$firstRow = $withCre['normalized_data']['rows'][0] ?? [];

assertCondition(($reviewWithCre['required'] ?? true) === false, 'CRE/Pasta preenchida nao deveria exigir revisao manual.', $failures);
assertCondition(!in_array('contrato', $columns, true), 'Coluna contrato nao deveria ser adicionada.', $failures);
assertCondition(in_array('cre_pasta', $columns, true), 'Coluna CRE/Pasta deveria ser adicionada.', $failures);
assertCondition(($firstRow['cre_pasta'] ?? '') === 'CRE 01', 'CRE/Pasta nao foi aplicado nas linhas.', $failures);

if ($failures !== []) {
    echo implode(PHP_EOL, $failures), PHP_EOL;
    exit(1);
}

echo 'PREFEITURA_RIO_CONTEXT_VALIDATION_OK', PHP_EOL;

function assertCondition(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = 'FAIL: ' . $message;
    }
}
