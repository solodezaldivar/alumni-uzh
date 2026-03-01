<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function out(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function safe_realpath(string $path): ?string {
    $rp = realpath($path);
    return ($rp !== false) ? $rp : null;
}

/**
 * Adjust this if your deployment differs.
 * This API is expected at /api/readme_list.php
 * and your archive folder at /readme/archive
 */
$archiveDir =
    safe_realpath(__DIR__ . '/../readme/archive')   // if api/ is sibling of readme/
    ?? safe_realpath(__DIR__ . '/../../readme/archive') // if api/ is deeper
    ?? null;

if ($archiveDir === null || !is_dir($archiveDir)) {
    http_response_code(500);
    out(['ok' => false, 'error' => 'Archive folder not found.']);
}

// Build list of PDFs excluding latest.pdf
$pdfs = glob($archiveDir . '/*.pdf') ?: [];
$items = [];

foreach ($pdfs as $pdfPath) {
    $base = basename($pdfPath);
    if (strtolower($base) === 'latest.pdf') continue;

    $name = pathinfo($pdfPath, PATHINFO_FILENAME);
    $jpgPath = $archiveDir . '/' . $name . '.jpg';
    if (!is_file($jpgPath)) {
        // allow .jpeg fallback
        $jpegPath = $archiveDir . '/' . $name . '.jpeg';
        $jpgPath = is_file($jpegPath) ? $jpegPath : '';
    }

    $mtime = @filemtime($pdfPath) ?: time();
    $year = (int)date('Y', $mtime);

    // Build web paths (assumes /readme/archive is web-served)
    $pdfUrl = 'readme/archive/' . basename($pdfPath);
    $jpgUrl = $jpgPath ? ('readme/archive/' . basename($jpgPath)) : null;

    // Nicer label: readme54_25 => Readme 54/25
    $label = $name;
    if (preg_match('~^readme(\d+)[_-](\d{2})$~i', $name, $m)) {
        $label = 'Readme ' . $m[1] . '/' . $m[2];
    } elseif (preg_match('~^readme(\d+)$~i', $name, $m)) {
        $label = 'Readme ' . $m[1];
    }

    $items[] = [
        'year' => $year,
        'name' => $name,
        'label' => $label,
        'mtime' => $mtime,
        'date'  => date('d.m.Y', $mtime),
        'pdf'   => $pdfUrl,
        'jpg'   => $jpgUrl,
    ];
}

// Sort newest first
usort($items, fn($a, $b) => ($b['mtime'] <=> $a['mtime']));

// Also provide latest pointers
$latestPdf = is_file($archiveDir . '/latest.pdf') ? 'readme/archive/latest.pdf' : null;
$latestJpg = is_file($archiveDir . '/latest.jpg') ? 'readme/archive/latest.jpg' : null;

out([
    'ok' => true,
    'latest' => [
        'pdf' => $latestPdf,
        'jpg' => $latestJpg,
    ],
    'items' => $items,
]);