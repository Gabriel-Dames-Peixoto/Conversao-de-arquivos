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
                'method' => 'none',
            ];
        }

        $externalExtraction = $this->extractWithPdftotext($filePath);
        if ($externalExtraction['text'] !== '') {
            return $externalExtraction;
        }

        $warnings = array_merge($warnings, $externalExtraction['warnings']);
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
            'warnings' => array_values(array_unique($warnings)),
            'method' => 'internal_stream_parser',
        ];
    }

    public function extractTabularRows(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

        if (count($lines) < 3) {
            return [];
        }

        $candidates = [];
        foreach ($lines as $line) {
            $delimiter = $this->guessDelimiter($line);
            if ($delimiter === null) {
                continue;
            }

            $parts = $this->splitByDelimiter($line, $delimiter);

            if (count($parts) >= 2) {
                $candidates[] = [
                    'delimiter' => $delimiter,
                    'parts' => $parts,
                ];
            }
        }

        $table = $this->bestTableCandidate($candidates);
        if ($table === null) {
            return [];
        }

        $headerParts = $table['headers'];
        $rows = [];
        foreach ($table['rows'] as $parts) {
            $row = [];
            foreach ($headerParts as $index => $header) {
                $row[$header] = trim((string) ($parts[$index] ?? ''));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function bestTableCandidate(array $candidates): ?array
    {
        $best = null;
        $bestScore = 0;
        $count = count($candidates);

        for ($index = 0; $index < $count; $index++) {
            $headers = $candidates[$index]['parts'];
            $columnCount = count($headers);

            if ($columnCount < 2 || !$this->looksLikeHeader($headers)) {
                continue;
            }

            $rows = [];
            for ($next = $index + 1; $next < $count; $next++) {
                if (count($candidates[$next]['parts']) !== $columnCount) {
                    break;
                }

                $rows[] = $candidates[$next]['parts'];
            }

            if (count($rows) < 2) {
                continue;
            }

            $score = ($columnCount * 10) + count($rows);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'headers' => $headers,
                    'rows' => $rows,
                ];
            }
        }

        return $best;
    }

    private function looksLikeHeader(array $parts): bool
    {
        foreach ($parts as $part) {
            $part = trim((string) $part);

            if ($part === '' || strlen($part) > 40) {
                return false;
            }
        }

        return true;
    }

    private function splitByDelimiter(string $line, string $delimiter): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split($delimiter, $line) ?: []),
            static fn (string $value): bool => $value !== ''
        ));
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

    private function extractWithPdftotext(string $filePath): array
    {
        $warnings = [];

        foreach ($this->pdftotextCandidates() as $binary) {
            $result = $this->runPdftotext($binary, $filePath);

            if ($result['available'] === false) {
                continue;
            }

            if ($result['text'] !== '') {
                return [
                    'text' => $this->normalizeText($result['text']),
                    'warnings' => [],
                    'method' => 'pdftotext',
                ];
            }

            if ($result['error'] !== '') {
                $warnings[] = $result['error'];
            }
        }

        return [
            'text' => '',
            'warnings' => $warnings,
            'method' => 'pdftotext',
        ];
    }

    private function runPdftotext(string $binary, string $filePath): array
    {
        $command = [$binary, '-layout', $filePath, '-'];
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
            ];
        }

        fclose($pipes[0]);
        $text = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'available' => true,
            'text' => $exitCode === 0 ? trim((string) $text) : '',
            'error' => $exitCode === 0 ? '' : trim((string) $error),
        ];
    }

    private function pdftotextCandidates(): array
    {
        $candidates = [];
        $configured = getenv('PDFTOTEXT_PATH');

        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }

        $candidates[] = 'pdftotext';
        $candidates[] = 'C:\\poppler\\Library\\bin\\pdftotext.exe';

        return array_values(array_unique($candidates));
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

    private function normalizeText(string $text): string
    {
        $text = preg_replace("/\R{3,}/u", PHP_EOL . PHP_EOL, $text) ?: $text;

        return trim($text);
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
