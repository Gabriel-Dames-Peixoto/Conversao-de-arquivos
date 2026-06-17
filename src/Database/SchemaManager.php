<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class SchemaManager
{
    /** @var array<string,mixed> */
    private $config;

    /** @var string */
    private $schemaPath;

    public function __construct(array $config, string $schemaPath)
    {
        $this->config = $config;
        $this->schemaPath = $schemaPath;
    }

    public function ensureDatabaseAndSchema(): void
    {
        $serverDsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['charset']
        );

        $serverPdo = new PDO(
            $serverDsn,
            $this->config['username'],
            $this->config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $databaseName = str_replace('`', '``', $this->config['database']);
        $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET {$this->config['charset']} COLLATE {$this->config['charset']}_unicode_ci");

        $pdo = Connection::getInstance();
        $schemaSql = file_get_contents($this->schemaPath);

        if ($schemaSql === false) {
            throw new RuntimeException('Nao foi possivel ler o arquivo de schema do banco.');
        }

        foreach (array_filter(array_map('trim', explode(';', $schemaSql))) as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
