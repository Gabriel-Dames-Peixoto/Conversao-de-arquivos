<?php

declare(strict_types=1);

namespace App\Support;

final class PdfImageRenderer
{
    public function renderToImages(string $pdfPath): array
    {
        $warnings = [];

        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            return [
                'images' => [],
                'warnings' => ['Nao foi possivel ler o PDF para renderizacao OCR.'],
                'available' => false,
                'temp_dir' => null,
                'method' => 'pdftoppm',
            ];
        }

        foreach ($this->pdftoppmCandidates() as $binary) {
            $tempDir = $this->createTempDir();
            if ($tempDir === null) {
                return [
                    'images' => [],
                    'warnings' => ['Nao foi possivel criar diretorio temporario para renderizar o PDF.'],
                    'available' => false,
                    'temp_dir' => null,
                    'method' => 'pdftoppm',
                ];
            }

            $result = $this->runPdftoppm($binary, $pdfPath, $tempDir);

            if ($result['available'] === false) {
                $this->cleanup($tempDir);
                continue;
            }

            if ($result['images'] !== []) {
                return [
                    'images' => $result['images'],
                    'warnings' => [],
                    'available' => true,
                    'temp_dir' => $tempDir,
                    'method' => 'pdftoppm',
                ];
            }

            $warnings = array_merge($warnings, $result['warnings']);
            $this->cleanup($tempDir);
        }

        if ($warnings === []) {
            $warnings[] = 'Renderizacao OCR indisponivel: instale o Poppler/pdftoppm ou configure PDFTOPPM_PATH.';
        }

        return [
            'images' => [],
            'warnings' => array_values(array_unique($warnings)),
            'available' => false,
            'temp_dir' => null,
            'method' => 'pdftoppm',
        ];
    }

    public function cleanup(?string $directory): void
    {
        if ($directory === null || $directory === '' || !is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }

    private function runPdftoppm(string $binary, string $pdfPath, string $tempDir): array
    {
        $prefix = $tempDir . DIRECTORY_SEPARATOR . 'page';
        $command = [$binary, '-png', '-r', '300', $pdfPath, $prefix];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            $process = @proc_open($command, $descriptorSpec, $pipes);
        } catch (\Throwable) {
            $process = false;
        }

        if (!is_resource($process)) {
            return [
                'available' => false,
                'images' => [],
                'warnings' => [],
            ];
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $images = glob($prefix . '*.png') ?: [];
        sort($images, SORT_NATURAL);

        if ($exitCode === 0 && $images !== []) {
            return [
                'available' => true,
                'images' => $images,
                'warnings' => [],
            ];
        }

        return [
            'available' => true,
            'images' => [],
            'warnings' => [trim((string) $error) !== '' ? trim((string) $error) : 'Nao foi possivel renderizar paginas do PDF para OCR.'],
        ];
    }

    private function pdftoppmCandidates(): array
    {
        $candidates = [];
        $configured = getenv('PDFTOPPM_PATH');

        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }

        $candidates[] = 'pdftoppm';
        $candidates[] = 'C:\\poppler\\Library\\bin\\pdftoppm.exe';

        return array_values(array_unique($candidates));
    }

    private function createTempDir(): ?string
    {
        try {
            $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'file_conversion_pdf_ocr_' . bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return null;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        return $directory;
    }
}
