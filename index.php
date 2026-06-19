<?php

declare(strict_types=1);

$bootstrap = require __DIR__ . '/bootstrap.php';

use App\Repositories\UploadedFileRepository;
use App\Services\ImportContextService;
use App\Services\UploadProcessingService;

function index_request_value(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_array($value) ? '' : trim((string) $value);
}

function index_request_date(string $key): string
{
    $value = index_request_value($key);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

function index_requested_limit(): int
{
    $limit = (int) ($_GET['limit'] ?? 30);
    $allowed = [10, 20, 30, 50, 100];

    return in_array($limit, $allowed, true) ? $limit : 30;
}

function index_url_with(array $overrides): string
{
    $parameters = $_GET;

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($parameters[$key]);
            continue;
        }

        $parameters[$key] = (string) $value;
    }

    foreach ($parameters as $key => $value) {
        if (is_array($value) || $value === null || $value === '') {
            unset($parameters[$key]);
        }
    }

    return 'index.php' . ($parameters === [] ? '' : '?' . http_build_query($parameters));
}

function index_has_filters(array $filters): bool
{
    foreach ($filters as $value) {
        if ((string) $value !== '') {
            return true;
        }
    }

    return false;
}

function index_status_label(string $status): string
{
    $labels = [
        'processed' => 'processado',
        'processed_with_warning' => 'processado com aviso',
        'failed' => 'falhou',
        'unsupported' => 'nao suportado',
        'archived_missing' => 'arquivado: arquivo ausente',
        'reviewed' => 'revisado',
        'pending' => 'pendente',
    ];

    return $labels[$status] ?? $status;
}

function index_badge_class(string $status): string
{
    $class = (string) preg_replace('/[^a-z0-9_-]+/i', '-', $status);

    return $class === '' ? 'unknown' : $class;
}

$databaseError = $bootstrap['database_error'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$appConfig = $bootstrap['app_config'];
$uploads = [];
$typeOptions = [];
$statusOptions = [];
$filteredTotal = 0;
$tabCounts = [
    'active' => 0,
    'archived' => 0,
];
$currentTab = index_request_value('tab') === 'archived' ? 'archived' : 'active';
$limit = index_requested_limit();
$filters = [
    'date_from' => index_request_date('date_from'),
    'date_to' => index_request_date('date_to'),
    'name' => index_request_value('name'),
    'type' => index_request_value('type'),
    'status' => index_request_value('status'),
];

if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
    $oldDateFrom = $filters['date_from'];
    $filters['date_from'] = $filters['date_to'];
    $filters['date_to'] = $oldDateFrom;
}

$hasActiveFilters = index_has_filters($filters);

if ($databaseError === null) {
    $uploadRepository = new UploadedFileRepository();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
        try {
            $processingService = new UploadProcessingService($appConfig);
            $importContext = (new ImportContextService())->fromRequest($_POST);
            $result = $processingService->process($_FILES['upload_file'], $importContext);

            $_SESSION['flash'] = [
                'type' => $result['status'] === 'failed' ? 'error' : 'success',
                'message' => $result['message'],
            ];

            header('Location: file.php?id=' . $result['upload_id']);
            exit;
        } catch (\Throwable $exception) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Falha ao processar o upload: ' . $exception->getMessage(),
            ];

            header('Location: index.php');
            exit;
        }
    }

    $uploads = $uploadRepository->search($filters, $limit, $currentTab);
    $filteredTotal = $uploadRepository->countSearch($filters, $currentTab);
    $tabCounts = [
        'active' => $uploadRepository->countByTab('active'),
        'archived' => $uploadRepository->countByTab('archived'),
    ];
    $typeOptions = $uploadRepository->listFilterValues('detected_type', $currentTab);
    $statusOptions = $uploadRepository->listFilterValues('status', $currentTab);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversor de Arquivos</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main class="page-shell">
    <section class="hero-card">
        <div>
            <p class="eyebrow">Importacao e exportacao normalizada</p>
            <h1>Conversor de arquivos para importacao</h1>
            <p class="hero-copy">
                Envie arquivos de qualquer extensao, registre tudo no MySQL, normalize os formatos suportados
                e exporte os dados para novos formatos sem quebrar o fluxo quando o arquivo nao tiver conversor.
            </p>
        </div>
        <div class="status-grid">
            <div class="status-box">
                <strong>Formatos priorizados</strong>
                <span>CSV, TXT, JSON, XML, XLS, XLSX, PDF e imagens escaneadas</span>
            </div>
            <div class="status-box">
                <strong>Fallback seguro</strong>
                <span>Arquivos sem conversor sao aceitos, diagnosticados e preservados com metadados</span>
            </div>
        </div>
    </section>

    <?php if ($flash !== null): ?>
        <div class="alert <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($databaseError !== null): ?>
        <section class="panel">
            <h2>Banco de dados indisponivel</h2>
            <p>
                O sistema nao conseguiu inicializar o MySQL automaticamente. Ajuste as credenciais em
                <code>config/database.php</code> e confirme que o MySQL do Laragon esta ativo.
            </p>
            <pre class="error-block"><?= htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'); ?></pre>
        </section>
    <?php else: ?>
        <section class="panel">
            <h2>Novo upload</h2>
            <form action="index.php" method="post" enctype="multipart/form-data" class="upload-form">
                <label class="upload-input">
                    <span>Selecione o arquivo</span>
                    <input type="file" name="upload_file" required>
                </label>

                <div class="import-context-grid">
                    <label class="filter-field">
                        <span>Processo de importacao</span>
                        <select name="process_type">
                            <option value="">Geral / automatico</option>
                            <option value="prefeitura_rio">Prefeitura do Rio</option>
                        </select>
                    </label>

                    <label class="filter-field">
                        <span>CRE / pasta</span>
                        <input type="text" name="cre_folder" placeholder="Opcional, ex.: CRE 01">
                    </label>
                </div>

                <p class="helper-text">
                    Tamanho maximo: <?= number_format(($appConfig['max_upload_size'] / 1024 / 1024), 0, ',', '.'); ?> MB.
                    Para arquivos da Prefeitura do Rio, selecione o processo e informe a CRE/pasta apenas se quiser incluir essa referencia na exportacao.
                </p>
                <button type="submit">Enviar e processar</button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <p class="eyebrow">Organizacao dos arquivos</p>
                    <h2><?= $currentTab === 'archived' ? 'Arquivos arquivados' : 'Arquivos enviados'; ?></h2>
                </div>
                <span><?= count($uploads); ?> exibido(s) de <?= (int) $filteredTotal; ?> encontrado(s)</span>
            </div>

            <nav class="tabs" aria-label="Abas da listagem de arquivos">
                <a
                    class="tab-link <?= $currentTab === 'active' ? 'is-active' : ''; ?>"
                    href="<?= htmlspecialchars(index_url_with(['tab' => 'active', 'type' => null, 'status' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                >
                    Ativos
                    <span><?= (int) $tabCounts['active']; ?></span>
                </a>
                <a
                    class="tab-link <?= $currentTab === 'archived' ? 'is-active' : ''; ?>"
                    href="<?= htmlspecialchars(index_url_with(['tab' => 'archived', 'type' => null, 'status' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                >
                    Arquivados
                    <span><?= (int) $tabCounts['archived']; ?></span>
                </a>
            </nav>

            <form action="index.php" method="get" class="filter-form">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($currentTab, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="filter-grid">
                    <label class="filter-field">
                        <span>Periodo inicial</span>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="filter-field">
                        <span>Periodo final</span>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8'); ?>">
                    </label>

                    <label class="filter-field filter-field-wide">
                        <span>Nome do arquivo</span>
                        <input
                            type="search"
                            name="name"
                            placeholder="Buscar pelo nome"
                            value="<?= htmlspecialchars($filters['name'], ENT_QUOTES, 'UTF-8'); ?>"
                        >
                    </label>

                    <label class="filter-field">
                        <span>Tipo</span>
                        <select name="type">
                            <option value="">Todos</option>
                            <?php foreach ($typeOptions as $typeOption): ?>
                                <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $filters['type'] === $typeOption ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="filter-field">
                        <span>Status</span>
                        <select name="status">
                            <option value="">Todos</option>
                            <?php foreach ($statusOptions as $statusOption): ?>
                                <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $filters['status'] === $statusOption ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars(index_status_label($statusOption), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="filter-field">
                        <span>Qtd. exibida</span>
                        <select name="limit">
                            <?php foreach ([10, 20, 30, 50, 100] as $limitOption): ?>
                                <option value="<?= $limitOption; ?>" <?= $limit === $limitOption ? 'selected' : ''; ?>>
                                    <?= $limitOption; ?> por vez
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="filter-actions">
                    <button type="submit">Aplicar filtros</button>
                    <a
                        class="button-secondary"
                        href="<?= htmlspecialchars(index_url_with([
                            'tab' => $currentTab,
                            'date_from' => null,
                            'date_to' => null,
                            'name' => null,
                            'type' => null,
                            'status' => null,
                            'limit' => null,
                        ]), ENT_QUOTES, 'UTF-8'); ?>"
                    >
                        Limpar filtros
                    </a>
                </div>
            </form>

            <?php if ($uploads === []): ?>
                <p class="empty-state">
                    <?php if ($hasActiveFilters): ?>
                        Nenhum arquivo foi encontrado com os filtros aplicados.
                    <?php elseif ($currentTab === 'archived'): ?>
                        Nenhum registro arquivado foi encontrado.
                    <?php else: ?>
                        Nenhum arquivo foi enviado ainda.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Arquivo</th>
                            <th>Tipo detectado</th>
                            <th>MIME</th>
                            <th>Status</th>
                            <th>Data</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($uploads as $upload): ?>
                            <?php $status = (string) $upload['status']; ?>
                            <tr>
                                <td>#<?= (int) $upload['id']; ?></td>
                                <td><?= htmlspecialchars($upload['original_filename'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars((string) $upload['detected_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars((string) $upload['mime_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="badge badge-<?= htmlspecialchars(index_badge_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?= htmlspecialchars(index_status_label($status), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($upload['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($currentTab === 'archived'): ?>
                                        <span class="text-muted">Registro arquivado</span>
                                    <?php else: ?>
                                        <div class="row-actions">
                                            <a class="text-link" href="file.php?id=<?= (int) $upload['id']; ?>">Ver detalhes</a>
                                            <a class="text-link" href="download.php?id=<?= (int) $upload['id']; ?>">Baixar</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
