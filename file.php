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

function detail_column_label(string $column, array $labels): string
{
    $label = $labels[$column] ?? $column;

    return (string) $label;
}

function detail_preview_url(int $uploadId, string $filter, int $limit, int $page): string
{
    return 'file.php?' . http_build_query([
        'id' => $uploadId,
        'preview_filter' => $filter,
        'preview_limit' => $limit,
        'preview_page' => $page,
    ]);
}

function detail_column_class(string $column, array $quantityColumns): string
{
    $classes = [];

    if (isset($quantityColumns[$column])) {
        $classes[] = 'is-quantity-column';
    }

    if (in_array($column, ['fornecedor_nome', 'produto_nome', 'unidade_nome'], true)) {
        $classes[] = 'is-wide-text-column';
    }

    if (in_array($column, ['fornecedor_codigo', 'pedido', 'pedido_item', 'grupo', 'unidade_codigo', 'documento', 'produto_codigo', 'controle'], true)) {
        $classes[] = 'is-code-column';
    }

    return implode(' ', $classes);
}

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

$uploadFileAvailable = is_file((string) ($upload['storage_path'] ?? ''));
if ($upload['status'] === 'archived_missing' || !$uploadFileAvailable) {
    http_response_code(404);
    echo 'Arquivo removido da listagem porque o original nao esta mais disponivel.';
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
$metadata = is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [];
$columnLabels = is_array($metadata['column_labels'] ?? null) ? $metadata['column_labels'] : [];
$presentation = is_array($metadata['presentation'] ?? null) ? $metadata['presentation'] : null;
$presentationSummary = is_array($presentation['summary'] ?? null) ? $presentation['summary'] : [];
$presentationTitle = (string) ($presentation['title'] ?? 'Dados identificados');
$quantityColumns = [];

foreach (is_array($presentation['quantity_columns'] ?? null) ? $presentation['quantity_columns'] : [] as $quantityColumn) {
    if (is_array($quantityColumn) && isset($quantityColumn['column'])) {
        $quantityColumns[(string) $quantityColumn['column']] = true;
    }
}

$manualReview = is_array($normalized['metadata']['manual_review'] ?? null) ? $normalized['metadata']['manual_review'] : null;
$manualReviewPending = $manualReview !== null
    && ($manualReview['required'] ?? false) === true
    && ($manualReview['status'] ?? '') !== 'reviewed';
$reviewFields = is_array($manualReview['fields'] ?? null) ? $manualReview['fields'] : [];
$reviewIssues = is_array($manualReview['issues'] ?? null) ? $manualReview['issues'] : [];
$reviewCellMap = [];
$reviewRowMap = [];

foreach ($reviewFields as $field) {
    if (!is_array($field) || ($field['type'] ?? '') !== 'cell') {
        continue;
    }

    $reviewRowIndex = (int) ($field['row_index'] ?? -1);
    if ($reviewRowIndex < 0) {
        continue;
    }

    $reviewCellMap[$reviewRowIndex . '|' . (string) ($field['column'] ?? '')] = true;
    $reviewRowMap[$reviewRowIndex] = true;
}

$reviewConfidence = $manualReview !== null ? (int) round(((float) ($manualReview['confidence'] ?? 0)) * 100) : null;
$previewFilter = (string) ($_GET['preview_filter'] ?? 'all');
if (!in_array($previewFilter, ['all', 'review'], true)) {
    $previewFilter = 'all';
}

$previewLimitOptions = [25, 50, 100, 200];
$requestedPreviewLimit = isset($_GET['preview_limit']) ? (int) $_GET['preview_limit'] : (int) $previewRows;
$previewLimit = in_array($requestedPreviewLimit, $previewLimitOptions, true)
    ? $requestedPreviewLimit
    : (in_array((int) $previewRows, $previewLimitOptions, true) ? (int) $previewRows : 50);
$previewPage = max(1, isset($_GET['preview_page']) ? (int) $_GET['preview_page'] : 1);
$filteredRows = [];

foreach (is_array($rows) ? $rows : [] as $rowIndex => $row) {
    if ($previewFilter === 'review' && !isset($reviewRowMap[(int) $rowIndex])) {
        continue;
    }

    $filteredRows[$rowIndex] = $row;
}

$filteredRowCount = count($filteredRows);
$previewTotalPages = max(1, (int) ceil($filteredRowCount / max(1, $previewLimit)));
$previewPage = min($previewPage, $previewTotalPages);
$previewOffset = ($previewPage - 1) * $previewLimit;
$previewPageRows = array_slice($filteredRows, $previewOffset, $previewLimit, true);
$previewShowingStart = $filteredRowCount === 0 ? 0 : $previewOffset + 1;
$previewShowingEnd = $filteredRowCount === 0 ? 0 : min($filteredRowCount, $previewOffset + count($previewPageRows));
$reviewCellCount = count($reviewCellMap);
$reviewRowCount = count($reviewRowMap);
$paginationPages = [];
if ($previewTotalPages <= 7) {
    $paginationPages = range(1, $previewTotalPages);
} else {
    $paginationPages = array_values(array_unique(array_filter([
        1,
        $previewPage - 1,
        $previewPage,
        $previewPage + 1,
        $previewTotalPages,
    ], static fn (int $page): bool => $page >= 1 && $page <= $previewTotalPages)));
    sort($paginationPages);
}
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
<main class="page-shell detail-shell">
    <section class="panel">
        <div class="panel-header">
            <div>
                <p class="eyebrow">Detalhe do processamento</p>
                <h1><?= htmlspecialchars($upload['original_filename'], ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
            <div class="header-actions">
                <a class="button-secondary" href="download.php?id=<?= (int) $uploadId; ?>">Baixar arquivo enviado</a>
                <?php if (in_array($upload['status'], ['failed', 'unsupported'], true)): ?>
                    <form action="reprocess.php?id=<?= (int) $uploadId; ?>" method="post">
                        <button type="submit" class="button-secondary">Reprocessar</button>
                    </form>
                <?php endif; ?>
                <a class="text-link" href="index.php">Voltar</a>
            </div>
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
                    <h2>Campos pendentes de revisao</h2>
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
            <?php if ($manualReviewPending): ?>
                <div class="alert warning">
                    Conclua a revisao manual dos campos pendentes antes de exportar o arquivo final.
                </div>
            <?php endif; ?>

            <div class="export-actions">
                <?php foreach ($exportFormats as $format => $options): ?>
                    <?php if ($options['available'] && !$manualReviewPending): ?>
                        <a class="button-secondary" href="export.php?id=<?= (int) $uploadId; ?>&format=<?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($options['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php else: ?>
                        <span class="button-secondary button-disabled" title="<?= $manualReviewPending ? 'Conclua a revisao manual antes de exportar' : 'Indisponivel no ambiente atual'; ?>"><?= htmlspecialchars($options['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if ($presentation !== null && $presentationSummary !== []): ?>
                <div class="structured-preview">
                    <div class="structured-preview-header">
                        <div>
                            <p class="eyebrow">Informacoes identificadas</p>
                            <h3><?= htmlspecialchars($presentationTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
                        </div>
                        <span><?= count($rows); ?> item(ns) organizado(s)</span>
                    </div>

                    <div class="structured-summary-grid">
                        <?php foreach ($presentationSummary as $summaryItem): ?>
                            <?php if (is_array($summaryItem)): ?>
                                <article class="structured-summary-card">
                                    <strong><?= htmlspecialchars((string) ($summaryItem['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?= htmlspecialchars((string) ($summaryItem['value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                </article>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-toolbar">
                <div>
                    <strong>Visualizacao da tabela</strong>
                    <span>
                        Mostrando <?= (int) $previewShowingStart; ?>-<?= (int) $previewShowingEnd; ?>
                        de <?= (int) $filteredRowCount; ?> linha(s)
                        <?php if ($previewFilter === 'review'): ?>
                            com baixa confianca
                        <?php endif; ?>
                    </span>
                    <?php if ($reviewCellCount > 0): ?>
                        <small><?= (int) $reviewCellCount; ?> celula(s) em <?= (int) $reviewRowCount; ?> linha(s) marcada(s) para revisao.</small>
                    <?php endif; ?>
                </div>

                <form action="file.php" method="get" class="table-filter-form">
                    <input type="hidden" name="id" value="<?= (int) $uploadId; ?>">
                    <input type="hidden" name="preview_page" value="1">

                    <label>
                        Filtro
                        <select name="preview_filter">
                            <option value="all" <?= $previewFilter === 'all' ? 'selected' : ''; ?>>Todas as linhas</option>
                            <option value="review" <?= $previewFilter === 'review' ? 'selected' : ''; ?>>Somente baixa confianca</option>
                        </select>
                    </label>

                    <label>
                        Linhas por pagina
                        <select name="preview_limit">
                            <?php foreach ($previewLimitOptions as $limitOption): ?>
                                <option value="<?= (int) $limitOption; ?>" <?= $previewLimit === $limitOption ? 'selected' : ''; ?>>
                                    <?= (int) $limitOption; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button type="submit" class="button-secondary">Aplicar</button>
                </form>
            </div>

            <?php if ($previewFilter === 'review' && $reviewCellCount === 0): ?>
                <p class="empty-state">
                    Nenhuma celula da tabela foi marcada com baixa confianca. Se houver pendencias gerais, elas aparecem no painel de revisao manual acima.
                </p>
            <?php elseif ($previewPageRows === []): ?>
                <p class="empty-state">Nenhuma linha encontrada para os filtros selecionados.</p>
            <?php else: ?>
                <div class="table-wrapper preview-table-wrapper">
                    <table class="data-preview-table">
                        <thead>
                        <tr>
                            <th class="row-index-column">Linha</th>
                            <?php foreach ($columns as $column): ?>
                                <?php $columnKey = (string) $column; ?>
                                <th class="<?= htmlspecialchars(detail_column_class($columnKey, $quantityColumns), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?= htmlspecialchars(detail_column_label($columnKey, $columnLabels), ENT_QUOTES, 'UTF-8'); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($previewPageRows as $rowIndex => $row): ?>
                            <tr>
                                <td class="row-index-column"><?= (int) $rowIndex + 1; ?></td>
                                <?php foreach ($columns as $column): ?>
                                    <?php
                                    $columnKey = (string) $column;
                                    $needsReview = isset($reviewCellMap[((int) $rowIndex) . '|' . $columnKey]);
                                    $cellClasses = array_filter([
                                        $needsReview ? 'cell-needs-review' : '',
                                        detail_column_class($columnKey, $quantityColumns),
                                    ]);
                                    ?>
                                    <td class="<?= htmlspecialchars(implode(' ', $cellClasses), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($row[$columnKey] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($previewTotalPages > 1): ?>
                    <nav class="pagination" aria-label="Paginacao da previa">
                        <?php if ($previewPage > 1): ?>
                            <a href="<?= htmlspecialchars(detail_preview_url($uploadId, $previewFilter, $previewLimit, $previewPage - 1), ENT_QUOTES, 'UTF-8'); ?>">Anterior</a>
                        <?php else: ?>
                            <span class="is-disabled">Anterior</span>
                        <?php endif; ?>

                        <?php foreach ($paginationPages as $paginationPage): ?>
                            <?php if ($paginationPage === $previewPage): ?>
                                <span class="is-current"><?= (int) $paginationPage; ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(detail_preview_url($uploadId, $previewFilter, $previewLimit, (int) $paginationPage), ENT_QUOTES, 'UTF-8'); ?>"><?= (int) $paginationPage; ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if ($previewPage < $previewTotalPages): ?>
                            <a href="<?= htmlspecialchars(detail_preview_url($uploadId, $previewFilter, $previewLimit, $previewPage + 1), ENT_QUOTES, 'UTF-8'); ?>">Proxima</a>
                        <?php else: ?>
                            <span class="is-disabled">Proxima</span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
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
