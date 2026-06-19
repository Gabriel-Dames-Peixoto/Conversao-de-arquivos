<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Autoloader.php';

App\Support\Autoloader::register(__DIR__ . '/../src');

$file = __DIR__ . '/../storage/uploads/20260619135554_85775abf1a3a2f20.txt';

if (!is_file($file)) {
    echo 'FIXED_WIDTH_SCHEDULE_SKIPPED fixture_not_found', PHP_EOL;
    exit(0);
}

$detection = (new App\Services\FileDetectionService())->detect($file, 'Pref. Rio.TXT');
$result = (new App\Services\FileConversionService())->convert($file, $detection, 'Pref. Rio.TXT');
$normalized = $result['normalized_data'];
$columns = $normalized['columns'] ?? [];
$rows = $normalized['rows'] ?? [];
$firstRow = $rows[0] ?? [];
$failures = [];

assertCondition($result['converter'] === 'FixedWidthScheduleConverter', 'Conversor de largura fixa nao foi acionado.', $failures);
assertCondition(count($rows) === 3408, 'Quantidade de linhas esperada era 3408.', $failures);
assertCondition(in_array('produto_nome', $columns, true), 'Coluna produto_nome ausente.', $failures);
assertCondition(in_array('qtd_12_06_2026', $columns, true), 'Coluna de quantidade por data ausente.', $failures);
assertCondition(($firstRow['produto_nome'] ?? '') === 'FILE FRANGO', 'Primeiro produto nao foi extraido corretamente.', $failures);
assertCondition(($firstRow['qtd_12_06_2026'] ?? '') === '11,00', 'Primeira quantidade nao foi extraida corretamente.', $failures);

if ($failures !== []) {
    echo implode(PHP_EOL, $failures), PHP_EOL;
    exit(1);
}

echo 'FIXED_WIDTH_SCHEDULE_VALIDATION_OK rows=' . count($rows), PHP_EOL;

function assertCondition(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = 'FAIL: ' . $message;
    }
}
