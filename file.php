<?php

declare(strict_types=1);

$bootstrap = require __DIR__ . '/bootstrap.php';

use App\Repositories\ExportedFileRepository;
use App\Repositories\NormalizedDataRepository;
use App\Repositories\ConversionRunRepository;
use App\Repositories\ProcessingLogRepository;
use App\Repositories\UploadedFileRepository;
use App\Services\ExportService;
use App\Services\ManualReviewService;

$databaseError = $bootstrap['database_error'];

if ($databaseError !== null) {
    http_response_code(500);
    echo 'Banco de dados indisponivel: ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8');
    exit;
}

$uploadId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$uploadRepository = new UploadedFileRepository();
$normalizedRepository = new NormalizedDataRepository();
$logRepository = new ProcessingLogRepository();
$exportRepository = new ExportedFileRepository();
$conversionRunRepository = new ConversionRunRepository();

$upload = $uploadRepository->find($uploadId);

if ($upload === null) {
    http_response_code(404);
    echo 'Arquivo nao encontrado.';
    exit;
}

$normalized = $normalizedRepository->findByUploadId($uploadId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_review') {
    try {
        $manualFields = is_array($_POST['manual_fields'] ?? null) ? $_POST['manual_fields'] : [];
        $reviewResult = (new ManualReviewService())->save($upload, $manualFields);

        $_SESSION['flash'] = [
            'type' => $reviewResult['status'] === 'reviewed' ? 'success' : 'warning',
            'message' => $reviewResult['message'],
        ];
    } catch (\Throwable $exception) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => 'Falha ao salvar a revisao manual: ' . $exception->getMessage(),
        ];
    }

    header('Location: file.php?id=' . $uploadId);
    exit;
}

$logs = $logRepository->forUpload($uploadId);
$exports = $exportRepository->forUpload($uploadId);
$conversionRuns = $conversionRunRepository->forUpload($uploadId);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$previewRows = $bootstrap['app_config']['preview_rows'];
$rows = $normalized['rows'] ?? [];
$columns = $normalized['columns'] ?? [];
$manualReview = is_array($normalized['metadata']['manual_review'] ?? null) ? $normalized['metadata']['manual_review'] : null;
$reviewFields = is_array($manualReview['fields'] ?? null) ? $manualReview['fields'] : [];
$reviewIssues = is_array($manualReview['issues'] ?? null) ? $manualReview['issues'] : [];
$reviewCellMap = [];

foreach ($reviewFields as $field) {
    if (!is_array($field) || ($field['type'] ?? '') !== 'cell') {
        continue;
    }

    $reviewCellMap[((int) ($field['row_index'] ?? -1)) . '|' . (string) ($field['column'] ?? '')] = true;
}

$reviewConfidence = $manualReview !== null ? (int) round(((float) ($manualReview['confidence'] ?? 0)) * 100) : null;
$exportFormats = [
    'csv' => ['label' => 'Exportar CSV', 'available' => ExportService::isFormatAvailable('csv')],
    'json' => ['label' => 'Exportar JSON', 'available' => ExportService::isFormatAvailable('json')],
    'xml' => ['label' => 'Exportar XML', 'available' => ExportService::isFormatAvailable('xml')],
    'xlsx' => ['label' => 'Exportar XLSX', 'available' => ExportService::isFormatAvailable('xlsx')],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do arquivo #<?= (int) $uploadId; ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main class="page-shell">
    <section class="panel">
        <div class="panel-header">
            <div>
                <p class="eyebrow">Detalhe do processamento</p>
                <h1><?= htmlspecialchars($upload['original_filename'], ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
            <a class="text-link" href="index.php">Voltar</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?>">
                <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="detail-grid">
            <div class="detail-card">
                <strong>Status</strong>
                <span class="badge badge-<?= htmlspecialchars($upload['status'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($upload['status'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="detail-card">
                <strong>Tipo detectado</strong>
                <span><?= htmlspecialchars((string) $upload['detected_type'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="detail-card">
                <strong>MIME</strong>
                <span><?= htmlspecialchars((string) $upload['mime_type'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="detail-card">
                <strong>Conversor</strong>
                <span><?= htmlspecialchars((string) $upload['converter_name'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>

        <?php if (!empty($upload['error_message'])): ?>
            <pre class="error-block"><?= htmlspecialchars($upload['error_message'], ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php endif; ?>
    </section>

    <?php if ($manualReview !== null && ($manualReview['required'] ?? false) === true): ?>
        <section class="panel review-panel">
            <div class="panel-header">
                <div>
                    <p class="eyebrow">Revisao manual</p>
                    <h2>Areas com baixa confianca de leitura</h2>
                </div>
                <span class="badge badge-<?= ($manualReview['status'] ?? '') === 'reviewed' ? 'reviewed' : 'warning'; ?>">
                    <?= ($manualReview['status'] ?? '') === 'reviewed' ? 'revisado' : 'pendente'; ?>
                </span>
            </div>

            <div class="review-summary">
                <div>
                    <strong>Confianca estimada</strong>
                    <span><?= (int) $reviewConfidence; ?>%</span>
                </div>
                <p><?= htmlspecialchars((string) ($manualReview['summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <?php if ($reviewIssues !== []): ?>
                <div class="issue-list">
                    <?php foreach (array_slice($reviewIssues, 0, 8) as $issue): ?>
                        <?php if (is_array($issue)): ?>
                            <article>
                                <strong><?= htmlspecialchars((string) ($issue['area'] ?? 'Area nao identificada'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span><?= htmlspecialchars((string) ($issue['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                            </article>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="file.php?id=<?= (int) $uploadId; ?>" method="post" class="manual-review-form">
                <input type="hidden" name="action" value="manual_review">

                <div class="manual-fields-grid">
                    <?php foreach ($reviewFields as $field): ?>
                        <?php
                        if (!is_array($field)) {
                            continue;
                        }

                        $fieldKey = (string) ($field['key'] ?? '');
                        $fieldValue = (string) ($field['manual_value'] ?? '');
                        $fieldReadValue = (string) ($field['current_value'] ?? '');
                        $isLongField = ($field['type'] ?? '') === 'document_field' && $fieldKey === 'document_summary';
                        ?>
                        <label class="manual-field <?= ($field['resolved'] ?? false) ? 'is-resolved' : ''; ?>">
                            <span>
                                <?= htmlspecialchars((string) ($field['label'] ?? $fieldKey), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (($field['required'] ?? false) === true): ?>
                                    <small>obrigatorio</small>
                                <?php endif; ?>
                            </span>
                            <?php if ($isLongField): ?>
                                <textarea name="manual_fields[<?= htmlspecialchars($fieldKey, ENT_QUOTES, 'UTF-8'); ?>]" rows="4"><?= htmlspecialchars($fieldValue, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <?php else: ?>
                                <input type="text" name="manual_fields[<?= htmlspecialchars($fieldKey, ENT_QUOTES, 'UTF-8'); ?>]" value="<?= htmlspecialchars($fieldValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php endif; ?>
                            <em>
                                <?= htmlspecialchars((string) ($field['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($fieldReadValue !== '' && $fieldValue === ''): ?>
                                    <br>Valor lido: <?= htmlspecialchars($fieldReadValue, ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </em>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button type="submit">Salvar revisao manual</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-header">
            <h2>Previa dos dados normalizados</h2>
            <?php if ($normalized !== null): ?>
                <span><?= (int) $normalized['total_rows']; ?> linha(s) e <?= (int) $normalized['total_columns']; ?> coluna(s)</span>
            <?php endif; ?>
        </div>

        <?php if ($normalized === null): ?>
            <p class="empty-state">Nenhum dado normalizado foi salvo para este arquivo.</p>
        <?php else: ?>
            <div class="export-actions">
                <?php foreach ($exportFormats as $format => $options): ?>
                    <?php if ($options['available']): ?>
                        <a class="button-secondary" href="export.php?id=<?= (int) $uploadId; ?>&format=<?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($options['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php else: ?>
                        <span class="button-secondary button-disabled" title="Indisponivel no ambiente atual"><?= htmlspecialchars($options['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th><?= htmlspecialchars((string) $column, ENT_QUOTES, 'UTF-8'); ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($rows, 0, $previewRows, true) as $rowIndex => $row): ?>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <?php $needsReview = isset($reviewCellMap[((int) $rowIndex) . '|' . (string) $column]); ?>
                                <td class="<?= $needsReview ? 'cell-needs-review' : ''; ?>"><?= htmlspecialchars((string) ($row[$column] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Execucoes de conversao</h2>
        <?php if ($conversionRuns === []): ?>
            <p class="empty-state">Nenhuma execucao de conversao registrada.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>Conversor</th>
                        <th>Tipo detectado</th>
                        <th>Status</th>
                        <th>Mensagem</th>
                        <th>Data</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($conversionRuns as $conversionRun): ?>
                        <tr>
                            <td><?= htmlspecialchars($conversionRun['converter_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) $conversionRun['detected_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge badge-<?= htmlspecialchars($conversionRun['status'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($conversionRun['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td>
                                <?= htmlspecialchars((string) $conversionRun['message'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (($conversionRun['warnings'] ?? []) !== []): ?>
                                    <div class="warning-list">
                                        <?php foreach ($conversionRun['warnings'] as $warning): ?>
                                            <p><?= htmlspecialchars((string) $warning, ENT_QUOTES, 'UTF-8'); ?></p>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($conversionRun['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Historico de processamento</h2>
        <?php if ($logs === []): ?>
            <p class="empty-state">Nenhum log registrado.</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($logs as $log): ?>
                    <article class="timeline-item">
                        <header>
                            <strong><?= htmlspecialchars($log['stage'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span class="badge badge-<?= htmlspecialchars($log['status'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($log['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </header>
                        <p><?= htmlspecialchars((string) $log['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <time><?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8'); ?></time>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Arquivos exportados</h2>
        <?php if ($exports === []): ?>
            <p class="empty-state">Nenhuma exportacao registrada ainda.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>Formato</th>
                        <th>Status</th>
                        <th>Arquivo</th>
                        <th>Data</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($exports as $export): ?>
                        <tr>
                            <td><?= htmlspecialchars($export['format'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge badge-<?= htmlspecialchars($export['status'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($export['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?= htmlspecialchars($export['original_export_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($export['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
