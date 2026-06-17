<?php

declare(strict_types=1);

namespace App\Contracts;

interface ConverterInterface
{
    public function supports(array $detection): bool;

    public function convert(string $filePath, array $detection, string $originalFilename): array;

    public function getName(): string;
}
