<?php

declare(strict_types=1);

namespace App\Support;

final class PdfTextExtractor
{
    public function extract(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $warnings = [];

        if ($content === false) {
            return [
                'text' => '',
                'warnings' => ['Falha ao ler o arquivo PDF.'],
            ];
        }

        $text = '';
        if (preg_match_all('/stream(.*?)endstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = $this->decodeStream((string) $stream);
                $piece = trim($this->extractReadableText($decoded));
                if ($piece !== '') {
                    $text .= ($text !== '' ? PHP_EOL : '') . $piece;
                }
            }
        }

        $text = preg_replace("/[ \t]+/u", ' ', $text) ?: '';
        $text = trim($text);

        if ($text === '') {
            $warnings[] = 'Nao foi possivel extrair texto legivel diretamente do PDF.';
        }

        return [
            'text' => $text,
            'warnings' => $warnings,
        ];
    }

    public function extractTabularRows(string $text): array
    {
        $lines = preg_split('/(?<=[a-zA-Z0-9])\s{2,}(?=[a-zA-Z0-9])|\R/u', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

        if (count($lines) < 3) {
            return [];
        }

        $candidateLines = [];
        foreach ($lines as $line) {
            if (preg_match('/\s{2,}|\t|;|,|\|/', $line) === 1) {
                $candidateLines[] = $line;
            }
        }

        if (count($candidateLines) < 2) {
            return [];
        }

        $delimiter = $this->guessDelimiter($candidateLines[0]);
        if ($delimiter === null) {
            return [];
        }

        $headerParts = array_values(array_filter(array_map('trim', preg_split($delimiter, $candidateLines[0]) ?: []), static fn (string $value): bool => $value !== ''));
        if ($headerParts === []) {
            return [];
        }

        $rows = [];
        foreach (array_slice($candidateLines, 1) as $line) {
            $parts = preg_split($delimiter, $line) ?: [];
            $row = [];
            foreach ($headerParts as $index => $header) {
                $row[$header] = trim((string) ($parts[$index] ?? ''));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function decodeStream(string $stream): string
    {
        $stream = ltrim($stream, "\r\n");

        foreach (['gzuncompress', 'gzdecode'] as $decoder) {
            try {
                $decoded = @$decoder($stream);
                if (is_string($decoded) && $decoded !== '') {
                    return $decoded;
                }
            } catch (\Throwable) {
            }
        }

        return $stream;
    }

    private function extractReadableText(string $content): string
    {
        $text = '';

        if (preg_match_all('/\((.*?)\)\s*Tj/s', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $text .= ' ' . $match;
            }
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $arrayMatches)) {
            foreach ($arrayMatches[1] as $match) {
                if (preg_match_all('/\((.*?)\)/s', $match, $innerMatches)) {
                    foreach ($innerMatches[1] as $inner) {
                        $text .= ' ' . $inner;
                    }
                }
            }
        }

        return preg_replace('/\\\\([nrtbf()\\\\])/', ' ', $text) ?: '';
    }

    private function guessDelimiter(string $line): ?string
    {
        foreach (['/\t+/', '/\s{2,}/', '/;/', '/,/', '/\|/'] as $delimiter) {
            if (preg_match($delimiter, $line) === 1) {
                return $delimiter;
            }
        }

        return null;
    }
}
