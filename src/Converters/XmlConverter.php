<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;
use RuntimeException;
use SimpleXMLElement;

final class XmlConverter extends AbstractConverter implements ConverterInterface
{
    public function supports(array $detection): bool
    {
        return $detection['detected_type'] === 'xml';
    }

    public function getName(): string
    {
        return 'XmlConverter';
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        $this->ensureReadableAndNotEmpty($filePath, 'XML');

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);

        if (!$xml instanceof SimpleXMLElement) {
            $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
            libxml_clear_errors();
            throw new RuntimeException('Falha ao interpretar XML: ' . implode('; ', $errors));
        }

        $rows = $this->extractRows($xml);
        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }
        }

        if ($rows === []) {
            $rows[] = $this->flattenNode($xml);
            $columns = array_keys($rows[0]);
        }

        if ($columns === []) {
            throw new RuntimeException('O XML foi lido, mas nao possui estrutura de dados utilizavel.');
        }

        $normalizedRows = [];

        foreach ($rows as $row) {
            $normalizedRow = [];
            foreach ($columns as $column) {
                $normalizedRow[$column] = $this->normalizeScalar($row[$column] ?? '');
            }
            $normalizedRows[] = $normalizedRow;
        }

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => 'XML importado com sucesso.',
            'warnings' => [],
            'error' => null,
            'metadata' => $this->baseMetadata($detection, $originalFilename),
            'normalized_data' => $this->buildNormalizedData($columns, $normalizedRows, $this->baseMetadata($detection, $originalFilename)),
        ];
    }

    private function extractRows(SimpleXMLElement $xml): array
    {
        $children = $xml->children();
        $rows = [];

        foreach ($children as $child) {
            $row = $this->flattenNode($child);
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function flattenNode(SimpleXMLElement $node, string $prefix = ''): array
    {
        $result = [];

        foreach ($node->attributes() as $name => $value) {
            $result[$prefix . '@' . $name] = (string) $value;
        }

        $children = $node->children();

        if (count($children) === 0) {
            $value = trim((string) $node);
            if ($value !== '') {
                $result[$prefix !== '' ? rtrim($prefix, '.') : 'value'] = $value;
            }

            return $result;
        }

        foreach ($children as $name => $child) {
            $childPrefix = $prefix . $name . '.';
            $result += $this->flattenNode($child, $childPrefix);
        }

        $value = trim((string) $node);
        if ($value !== '' && $prefix !== '') {
            $result[rtrim($prefix, '.')] = $value;
        }

        return $result;
    }
}
