<?php

declare(strict_types=1);

namespace App\Converters;

use App\Contracts\ConverterInterface;

final class UnsupportedFileConverter extends AbstractConverter implements ConverterInterface
{
    public function supports(array $detection): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'UnsupportedFileConverter';
    }

    public function convert(string $filePath, array $detection, string $originalFilename): array
    {
        $extension = $detection['extension'] !== '' ? '.' . $detection['extension'] : 'sem extensao';
        $metadata = array_merge(
            $this->baseMetadata($detection, $originalFilename),
            ['conversion_mode' => 'metadata_only']
        );

        return [
            'status' => 'processed',
            'converter' => $this->getName(),
            'message' => 'Arquivo registrado como metadados porque ainda nao existe conversor para este formato.',
            'warnings' => ["Extensao {$extension} sem conversor de conteudo disponivel no momento."],
            'error' => null,
            'metadata' => $metadata,
            'normalized_data' => $this->buildNormalizedData(
                ['campo', 'valor'],
                [
                    ['campo' => 'arquivo', 'valor' => $originalFilename],
                    ['campo' => 'extensao', 'valor' => $extension],
                    ['campo' => 'tipo_detectado', 'valor' => $detection['detected_type'] ?? 'unsupported'],
                    ['campo' => 'observacao', 'valor' => 'Conteudo nao convertido; registro preservado com metadados.'],
                ],
                $metadata
            ),
        ];
    }
}
