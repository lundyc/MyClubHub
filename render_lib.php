<?php

declare(strict_types=1);

/**
 * @param array<int, string> $lines
 * @return array<int, string>
 */
function render_normalize_lines(array $lines): array
{
    $normalized = [];

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed !== '') {
            $normalized[] = $trimmed;
        }
    }

    return $normalized;
}

/**
 * Run the shared Puppeteer renderer and move the generated PNG into place.
 *
 * @return array{ok: bool, error: string, output: array<int, string>}
 */
function render_capture_image(
    string $url,
    string $outputPath,
    string $selector,
    int $width,
    int $height,
    ?string $waitSelector = null,
    int $deviceScaleFactor = 1
): array {
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        return [
            'ok' => false,
            'error' => 'Output directory could not be created.',
            'output' => [],
        ];
    }

    $tempBase = tempnam($outputDir, 'render-');
    if ($tempBase === false) {
        return [
            'ok' => false,
            'error' => 'Temporary export file could not be created.',
            'output' => [],
        ];
    }

    $tempPath = $tempBase . '.png';
    if (!@rename($tempBase, $tempPath)) {
        @unlink($tempBase);
        return [
            'ok' => false,
            'error' => 'Temporary export file could not be prepared.',
            'output' => [],
        ];
    }

    $renderScript = __DIR__ . '/render.js';
    $command = 'node '
        . escapeshellarg($renderScript)
        . ' ' . escapeshellarg($url)
        . ' ' . escapeshellarg($tempPath)
        . ' ' . escapeshellarg($selector)
        . ' ' . escapeshellarg((string) $width)
        . ' ' . escapeshellarg((string) $height)
        . ' ' . escapeshellarg($waitSelector ?? $selector)
        . ' ' . escapeshellarg((string) max(1, $deviceScaleFactor));

    $outputLines = [];
    $status = 0;
    exec($command . ' 2>&1', $outputLines, $status);
    $details = render_normalize_lines($outputLines);

    if ($status !== 0 || !is_file($tempPath)) {
        @unlink($tempPath);
        return [
            'ok' => false,
            'error' => 'Image generation failed.',
            'output' => $details,
        ];
    }

    if (is_file($outputPath) && !@unlink($outputPath)) {
        @unlink($tempPath);
        return [
            'ok' => false,
            'error' => 'Generated image could not replace the existing export.',
            'output' => $details,
        ];
    }

    if (!@rename($tempPath, $outputPath)) {
        @unlink($tempPath);
        return [
            'ok' => false,
            'error' => 'Generated image could not be moved into the export directory.',
            'output' => $details,
        ];
    }

    @chmod($outputPath, 0664);

    return [
        'ok' => true,
        'error' => '',
        'output' => $details,
    ];
}
