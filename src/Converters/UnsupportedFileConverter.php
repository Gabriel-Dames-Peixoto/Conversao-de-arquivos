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

        return [
            'status' => 'unsupported',
            'converter' => $this->getName(),
            'message' => 'Arquivo aceito e registrado, mas ainda nao existe conversor para este formato.',
            'warnings' => ["Extensao {$extension} sem conversor disponivel no momento."],
            'error' => null,
            'metadata' => $this->baseMetadata($detection, $originalFilename),
            'normalized_data' => null,
        ];
    }
}
