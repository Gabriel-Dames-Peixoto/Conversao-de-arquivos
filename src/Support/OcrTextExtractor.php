<?php

declare(strict_types=1);

namespace App\Support;

final class OcrTextExtractor
{
    public const LOW_CONFIDENCE_THRESHOLD = 0.72;

    public function extractImage(string $imagePath): array
    {
        $warnings = [];

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            return [
                'text' => '',
                'warnings' => ['Nao foi possivel ler a imagem para OCR.'],
                'method' => 'tesseract',
                'available' => false,
                'confidence' => null,
                'word_count' => 0,
            ];
        }

        $availabilityChecked = false;
        foreach ($this->tesseractCandidates() as $binary) {
            foreach ($this->languageCandidates() as $language) {
                $result = $this->runTesseract($binary, $imagePath, $language);

                if ($result['available'] === false) {
                    continue 2;
                }

                $availabilityChecked = true;

                if ($result['text'] !== '') {
                    return [
                        'text' => $this->normalizeText($result['text']),
                        'warnings' => $result['confidence'] !== null && $result['confidence'] < self::LOW_CONFIDENCE_THRESHOLD
                            ? ['OCR executado com baixa confianca. Revise os dados extraidos.']
                            : [],
                        'method' => 'tesseract',
                        'available' => true,
                        'confidence' => $result['confidence'],
                        'word_count' => $result['word_count'],
                        'language' => $language,
                    ];
                }

                if ($result['error'] !== '') {
                    $warnings[] = $result['error'];
                }
            }
        }

        if (!$availabilityChecked) {
            $warnings[] = 'OCR indisponivel: instale o Tesseract ou configure TESSERACT_PATH para ler imagens escaneadas.';
        } elseif ($warnings === []) {
            $warnings[] = 'OCR executado, mas nenhum texto legivel foi encontrado na imagem.';
        }

        return [
            'text' => '',
            'warnings' => array_values(array_unique($warnings)),
            'method' => 'tesseract',
            'available' => $availabilityChecked,
            'confidence' => null,
            'word_count' => 0,
        ];
    }

    private function runTesseract(string $binary, string $imagePath, string $language): array
    {
        $command = [$binary, $imagePath, 'stdout', '-l', $language, '--psm', '6', 'tsv'];
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
                'text' => '',
                'error' => '',
                'confidence' => null,
                'word_count' => 0,
            ];
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return [
                'available' => true,
                'text' => '',
                'error' => trim((string) $error),
                'confidence' => null,
                'word_count' => 0,
            ];
        }

        $parsed = $this->parseTsv((string) $output);

        return [
            'available' => true,
            'text' => $parsed['text'],
            'error' => '',
            'confidence' => $parsed['confidence'],
            'word_count' => $parsed['word_count'],
        ];
    }

    private function parseTsv(string $tsv): array
    {
        $lines = preg_split('/\R/u', trim($tsv)) ?: [];
        if (count($lines) <= 1) {
            return [
                'text' => '',
                'confidence' => null,
                'word_count' => 0,
            ];
        }

        $headers = str_getcsv(array_shift($lines), "\t");
        $indexes = array_flip($headers);
        $textIndex = $indexes['text'] ?? null;
        $confidenceIndex = $indexes['conf'] ?? null;
        $pageIndex = $indexes['page_num'] ?? null;
        $blockIndex = $indexes['block_num'] ?? null;
        $paragraphIndex = $indexes['par_num'] ?? null;
        $lineIndex = $indexes['line_num'] ?? null;

        if ($textIndex === null || $confidenceIndex === null) {
            return [
                'text' => '',
                'confidence' => null,
                'word_count' => 0,
            ];
        }

        $lineWords = [];
        $confidences = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = str_getcsv($line, "\t");
            $word = trim((string) ($parts[$textIndex] ?? ''));
            $confidence = (float) ($parts[$confidenceIndex] ?? -1);

            if ($word === '' || $confidence < 0) {
                continue;
            }

            $key = implode(':', [
                $pageIndex !== null ? (string) ($parts[$pageIndex] ?? '0') : '0',
                $blockIndex !== null ? (string) ($parts[$blockIndex] ?? '0') : '0',
                $paragraphIndex !== null ? (string) ($parts[$paragraphIndex] ?? '0') : '0',
                $lineIndex !== null ? (string) ($parts[$lineIndex] ?? '0') : '0',
            ]);

            $lineWords[$key][] = $word;
            $confidences[] = max(0.0, min(100.0, $confidence));
        }

        $textLines = [];
        foreach ($lineWords as $words) {
            $textLines[] = trim(implode(' ', $words));
        }

        return [
            'text' => implode(PHP_EOL, array_values(array_filter($textLines))),
            'confidence' => $confidences === []
                ? null
                : round((array_sum($confidences) / count($confidences)) / 100, 2),
            'word_count' => count($confidences),
        ];
    }

    private function tesseractCandidates(): array
    {
        $candidates = [];
        $configured = getenv('TESSERACT_PATH');

        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }

        $candidates[] = 'tesseract';
        $candidates[] = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';

        return array_values(array_unique($candidates));
    }

    private function languageCandidates(): array
    {
        $configured = getenv('OCR_LANG');
        $languages = [];

        if (is_string($configured) && $configured !== '') {
            $languages[] = $configured;
        }

        $languages[] = 'por+eng';
        $languages[] = 'por';
        $languages[] = 'eng';

        return array_values(array_unique($languages));
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?: $text;
        $text = preg_replace("/\R{3,}/u", PHP_EOL . PHP_EOL, $text) ?: $text;

        return trim($text);
    }
}
