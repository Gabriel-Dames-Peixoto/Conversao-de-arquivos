<?php

declare(strict_types=1);

$bootstrap = require __DIR__ . '/bootstrap.php';

use App\Repositories\NormalizedDataRepository;
use App\Repositories\ProcessingLogRepository;
use App\Repositories\UploadedFileRepository;
use App\Services\UploadProcessingService;

$databaseError = $bootstrap['database_error'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$appConfig = $bootstrap['app_config'];
$uploads = [];

if ($databaseError === null) {
    $uploadRepository = new UploadedFileRepository();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
        try {
            $processingService = new UploadProcessingService($appConfig);
            $result = $processingService->process($_FILES['upload_file']);

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

    $uploads = $uploadRepository->latest(30);
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
                <span>CSV, TXT, JSON, XML, XLS, XLSX e PDF</span>
            </div>
            <div class="status-box">
                <strong>Fallback seguro</strong>
                <span>Arquivos nao suportados sao aceitos e marcados como unsupported</span>
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
                <p class="helper-text">
                    Tamanho maximo: <?= number_format(($appConfig['max_upload_size'] / 1024 / 1024), 0, ',', '.'); ?> MB.
                    O sistema usa deteccao por MIME, extensao e assinatura para escolher o conversor.
                </p>
                <button type="submit">Enviar e processar</button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2>Arquivos enviados</h2>
                <span><?= count($uploads); ?> registro(s) recente(s)</span>
            </div>

            <?php if ($uploads === []): ?>
                <p class="empty-state">Nenhum arquivo foi enviado ainda.</p>
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
                            <tr>
                                <td>#<?= (int) $upload['id']; ?></td>
                                <td><?= htmlspecialchars($upload['original_filename'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars((string) $upload['detected_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars((string) $upload['mime_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge badge-<?= htmlspecialchars($upload['status'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($upload['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?= htmlspecialchars($upload['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><a class="text-link" href="file.php?id=<?= (int) $upload['id']; ?>">Ver detalhes</a></td>
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
