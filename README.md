# Conversao de Arquivos

Sistema em PHP + MySQL para upload, identificacao, normalizacao e exportacao de arquivos em um ambiente Laragon.

## Objetivo

O projeto foi criado para:

- aceitar upload de arquivos de qualquer extensao
- identificar extensao, MIME e assinatura do arquivo
- registrar todo o processamento no MySQL
- converter formatos suportados para um padrao interno normalizado
- exibir previa dos dados quando houver conversao
- apontar areas com baixa confianca de leitura e solicitar preenchimento manual
- exportar os dados normalizados novamente

Arquivos sem conversor continuam sendo aceitos e registrados com status `unsupported`, sem derrubar o sistema.

## Fluxo principal

1. O usuario envia um arquivo.
2. O sistema salva o upload com seguranca.
3. O upload e registrado no MySQL.
4. O tipo do arquivo e detectado.
5. O conversor adequado e escolhido.
6. Os dados sao normalizados quando possivel.
7. O sistema avalia a confianca da leitura e cria uma revisao manual quando necessario.
8. O sistema mostra previa, historico, registros da conversao e campos pendentes.
9. Os dados podem ser exportados novamente depois dos ajustes manuais.

## Stack

- PHP
- MySQL
- Laragon
- Apache

## Configuracao do MySQL

As credenciais ficam em `config/database.php`.

Valores padrao:

- host: `127.0.0.1`
- port: `3306`
- database: `file_conversion`
- username: `root`
- password: vazio

Na primeira carga da aplicacao, o sistema tenta:

1. conectar no MySQL
2. criar o banco `file_conversion` se ele ainda nao existir
3. executar o schema em `database/schema.sql`

## Tabelas criadas

- `uploaded_files`: registra cada arquivo enviado
- `normalized_data`: armazena o payload normalizado
- `conversion_runs`: registra cada execucao de conversao relacionada ao upload
- `exported_files`: registra cada exportacao gerada
- `processing_logs`: guarda etapas, status e mensagens

## Como rodar no Laragon

1. Coloque a pasta em `C:\laragon\www\Projetos\Conversão de arquivos para importação`.
2. Inicie Apache e MySQL no Laragon.
3. Ajuste `config/database.php` se necessario.
4. Abra o projeto pelo navegador no host configurado pelo Laragon.
5. Envie um arquivo pela tela inicial.

Se o banco nao subir, a propria tela inicial mostra a mensagem de erro da conexao sem expor stack trace completa.

## Formatos suportados

Convertidos para o padrao interno:

- CSV
- TXT
- JSON
- XML
- PDF com tentativa de extracao de texto e heuristica simples para tabelas

Aceitos e registrados, mas com conversao parcial ou dependente do ambiente:

- XLSX: importacao e exportacao dependem da extensao `zip` do PHP
- PDF escaneado: e aceito, marcado para revisao manual quando nao houver texto extraivel e preparado para receber dados preenchidos pelo usuario

Aceitos, registrados e ainda nao convertidos:

- XLS binario legado
- extensoes desconhecidas

## Tratamento de erros implementado

O sistema trata explicitamente:

- arquivo vazio
- arquivo corrompido
- JSON invalido
- XML invalido
- CSV com separadores diferentes
- PDF sem texto extraivel
- areas com baixa confianca de leitura em arquivos enviados ou escaneados
- extensao desconhecida
- erro ao salvar no banco
- erro ao exportar

Comportamento esperado:

- a falha e registrada com status apropriado
- o sistema nao exibe stack trace crua para o usuario
- uploads nao suportados continuam rastreaveis
- campos duvidosos ficam visiveis na tela de detalhes e podem ser corrigidos manualmente
- se o banco falhar depois do salvamento do arquivo, o upload fisico e limpo para evitar arquivo orfao

## Estrutura principal

- `index.php`: upload e listagem
- `file.php`: detalhe, previa, logs e execucoes de conversao
- `export.php`: exportacao
- `src/Services`: regras de upload, deteccao, conversao e exportacao
- `src/Converters`: conversores por tipo
- `src/Repositories`: persistencia no MySQL
- `database/schema.sql`: schema do banco
- `storage/`: uploads, exportacoes e sessoes

## Como adicionar um novo conversor

1. Crie uma classe em `src/Converters`, por exemplo `YamlConverter.php`.
2. Implemente `App\Contracts\ConverterInterface`.
3. Adicione a logica de `supports()` para o tipo detectado.
4. Retorne o payload normalizado neste formato:

```json
{
  "columns": [],
  "rows": [],
  "metadata": {
    "original_filename": "",
    "extension": "",
    "mime_type": "",
    "detected_type": "",
    "total_rows": 0,
    "total_columns": 0,
    "processed_at": ""
  }
}
```

5. Registre o conversor em `src/Services/FileConversionService.php`.
6. Se necessario, ajuste a deteccao em `src/Services/FileDetectionService.php`.

## Estado atual

Ja validado neste ambiente:

- criacao do banco e das tabelas no MySQL
- importacao de CSV, JSON, XML e TXT
- fallback `unsupported` para extensao desconhecida
- processamento de PDF com aviso de OCR quando necessario
- exportacao para CSV, JSON, XML e XLSX quando `ZipArchive` esta habilitado no PHP do Apache

Implementado no fluxo atual:

- revisao manual para areas de baixa confianca, incluindo PDF escaneado sem texto extraivel

Limitacoes atuais:

- importacao e exportacao XLSX ficam indisponiveis em ambientes PHP sem a extensao `zip`/`ZipArchive`
- conversao completa de XLS ainda nao foi implementada
