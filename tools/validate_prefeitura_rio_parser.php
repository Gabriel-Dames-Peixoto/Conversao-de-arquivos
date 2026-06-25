<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Autoloader.php';

App\Support\Autoloader::register(__DIR__ . '/../src');

$fixture = __DIR__ . '/../tests/fixtures/prefeitura_rio/prefeitura_rio_exemplo_real.txt';
$parser = new App\Parsers\PrefeituraRioTxtPedidoParser();
$failures = [];

$result = $parser->parse($fixture);
$pedidos = $result['pedidos'] ?? [];
$firstPedido = $pedidos[0] ?? [];
$firstItem = $firstPedido['itens'][0] ?? [];

assertCondition(($result['layout_valido'] ?? false) === true, 'Layout deveria ser reconhecido.', $failures);
assertCondition(($result['cliente'] ?? '') === 'PREFEITURA_RIO', 'Cliente padronizado incorreto.', $failures);
assertCondition(($result['contrato'] ?? '') === 'C 139', 'Contrato/controle nao foi identificado.', $failures);
assertCondition(($result['data_entrega'] ?? '') === '2026-06-12', 'Data de entrega nao foi normalizada.', $failures);
assertCondition(($result['codigo_cliente'] ?? '') === '813', 'Codigo de cliente/unidade incorreto.', $failures);
assertCondition(($result['erros'] ?? []) === [], 'Parser retornou erros inesperados.', $failures);
assertCondition(count($pedidos) === 1, 'Fixture deveria gerar um pedido.', $failures);
assertCondition(count($firstPedido['itens'] ?? []) === 5, 'Fixture deveria gerar cinco itens com quantidade positiva.', $failures);
assertCondition(($firstItem['codigo_produto'] ?? '') === '89050300743', 'Codigo do primeiro produto incorreto.', $failures);
assertCondition(($firstItem['quantidade'] ?? '') === '11.00', 'Quantidade do primeiro item incorreta.', $failures);
assertCondition(($firstItem['sequencia_entrega'] ?? '') === '1', 'Sequencia de entrega incorreta.', $failures);
assertCondition(array_key_exists('unidade', $firstItem), 'Campo unidade deve existir no item.', $failures);

$invalidFixture = tempnam(sys_get_temp_dir(), 'prefeitura_rio_invalid_');
file_put_contents($invalidFixture, 'arquivo sem o layout fixo esperado');
$invalid = $parser->parse($invalidFixture);
@unlink($invalidFixture);

assertCondition(($invalid['layout_valido'] ?? true) === false, 'Layout invalido deveria ser rejeitado.', $failures);
assertCondition(in_array('Layout TXT da Prefeitura do Rio nao reconhecido.', $invalid['erros'] ?? [], true), 'Erro de layout nao retornado.', $failures);

if ($failures !== []) {
    echo implode(PHP_EOL, $failures), PHP_EOL;
    exit(1);
}

echo 'PREFEITURA_RIO_PARSER_VALIDATION_OK pedidos=' . count($pedidos) . ' itens=' . count($firstPedido['itens'] ?? []), PHP_EOL;

function assertCondition(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = 'FAIL: ' . $message;
    }
}
