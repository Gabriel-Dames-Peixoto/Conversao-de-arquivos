<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Autoloader.php';

App\Support\Autoloader::register(__DIR__ . '/../src');

use App\Services\FileConversionService;
use App\Services\FileDetectionService;
use App\Support\ReadabilityAnalyzer;

$tmpFiles = [];
$failures = [];

try {
    $imagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'file_conversion_scan_test_' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents(
        $imagePath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lrWqNwAAAABJRU5ErkJggg==')
    );
    $tmpFiles[] = $imagePath;

    $imageResult = convertAndAnalyze($imagePath, 'scan.png');
    assertCondition($imageResult['detection']['detected_type'] === 'image', 'Imagem PNG deveria ser detectada como image.', $failures);
    assertCondition($imageResult['conversion']['converter'] === 'ImageConverter', 'Imagem deveria usar ImageConverter.', $failures);
    assertCondition(($imageResult['review']['required'] ?? false) === true, 'Imagem sem texto/OCR confiavel deveria exigir revisao manual.', $failures);

    $pdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'file_conversion_blank_pdf_' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($pdfPath, blankPdf());
    $tmpFiles[] = $pdfPath;

    $pdfResult = convertAndAnalyze($pdfPath, 'scan.pdf');
    assertCondition($pdfResult['detection']['detected_type'] === 'pdf', 'PDF deveria ser detectado como pdf.', $failures);
    assertCondition($pdfResult['conversion']['converter'] === 'PdfConverter', 'PDF deveria usar PdfConverter.', $failures);
    assertCondition(($pdfResult['review']['required'] ?? false) === true, 'PDF sem texto extraivel deveria exigir revisao manual.', $failures);

    if ($failures !== []) {
        echo implode(PHP_EOL, $failures), PHP_EOL;
        exit(1);
    }

    echo 'OCR_FLOW_VALIDATION_OK', PHP_EOL;
    echo 'image_review_confidence=' . $imageResult['review']['confidence'], PHP_EOL;
    echo 'pdf_review_confidence=' . $pdfResult['review']['confidence'], PHP_EOL;
} finally {
    foreach ($tmpFiles as $tmpFile) {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}

function convertAndAnalyze(string $path, string $name): array
{
    $detector = new FileDetectionService();
    $converter = new FileConversionService();
    $analyzer = new ReadabilityAnalyzer();

    $detection = $detector->detect($path, $name);
    $conversion = $converter->convert($path, $detection, $name);
    $review = $analyzer->analyze($conversion['normalized_data'], $conversion);

    return [
        'detection' => $detection,
        'conversion' => $conversion,
        'review' => $review,
    ];
}

function assertCondition(bool $condition, string $message, array &$failures): void
{
    if (!$condition) {
        $failures[] = 'FAIL: ' . $message;
    }
}

function blankPdf(): string
{
    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 4 0 R >>\nendobj\n",
        "4 0 obj\n<< /Length 0 >>\nstream\n\nendstream\nendobj\n",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}
