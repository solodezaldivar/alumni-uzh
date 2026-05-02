<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

$EVENTS_FILE = __DIR__ . '/../events.json';
$UPLOADS_DIR = realpath(__DIR__ . '/../uploads');
$PYTHON      = trim((string) shell_exec('which python3')) ?: 'python3';
$PARSER      = __DIR__ . '/parse_pdf_events.py';

function jsonOut(bool $ok, string $msg = '', array $data = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function loadJson(string $path): array
{
    if (!file_exists($path)) return [];
    $h = fopen($path, 'r');
    flock($h, LOCK_SH);
    $d = json_decode(fread($h, filesize($path) ?: 1), true);
    flock($h, LOCK_UN);
    fclose($h);
    return is_array($d) ? $d : [];
}

function saveJson(string $path, array $data): bool
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    $ok  = file_put_contents($tmp,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX) !== false;
    return $ok && rename($tmp, $path);
}

// ── Only POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(false, 'POST required');
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    jsonOut(false, 'Ungültiges CSRF-Token');
}

// ── Resolve PDF — either an uploaded file or an existing path ────────────────
$eventsDir = $UPLOADS_DIR . '/events';
if (!is_dir($eventsDir)) {
    mkdir($eventsDir, 0755, true);
}

if (!empty($_FILES['events_pdf']['name'])) {
    // File upload path: validate, save to uploads/events/naechste_events.pdf
    $upload = $_FILES['events_pdf'];

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'Datei zu gross (php.ini upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE  => 'Datei zu gross (MAX_FILE_SIZE)',
            UPLOAD_ERR_PARTIAL    => 'Datei nur teilweise hochgeladen',
            UPLOAD_ERR_NO_FILE    => 'Keine Datei ausgewählt',
            UPLOAD_ERR_NO_TMP_DIR => 'Kein temporäres Verzeichnis',
            UPLOAD_ERR_CANT_WRITE => 'Schreibfehler auf Disk',
        ];
        jsonOut(false, $errMap[$upload['error']] ?? 'Upload-Fehler ' . $upload['error']);
    }

    // Verify it is actually a PDF
    $fh   = fopen($upload['tmp_name'], 'rb');
    $head = $fh ? fread($fh, 5) : '';
    if ($fh) fclose($fh);
    if ($head !== '%PDF-') {
        jsonOut(false, 'Die hochgeladene Datei ist keine gültige PDF');
    }

    $dest = $eventsDir . '/naechste_events.pdf';
    if (!move_uploaded_file($upload['tmp_name'], $dest)) {
        jsonOut(false, 'PDF konnte nicht gespeichert werden (Rechte?)');
    }
    $pdfPath = $dest;
} else {
    // Fallback: use an existing file in uploads/
    $rel     = trim((string) ($_POST['pdf_path'] ?? 'events/naechste_events.pdf'), '/');
    $pdfPath = realpath($UPLOADS_DIR . '/' . $rel);

    if (!$pdfPath || !str_starts_with($pdfPath, $UPLOADS_DIR . '/') || !is_file($pdfPath)) {
        jsonOut(false, 'PDF nicht gefunden: ' . htmlspecialchars($rel));
    }
}

// ── shell_exec available? ─────────────────────────────────────────────────────
if (!function_exists('shell_exec')) {
    jsonOut(false, 'shell_exec ist auf diesem Server deaktiviert');
}

// ── Run Python parser ─────────────────────────────────────────────────────────
$cmd    = escapeshellcmd($PYTHON) . ' ' . escapeshellarg($PARSER) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
$output = shell_exec($cmd);

if ($output === null || $output === '') {
    jsonOut(false, 'Parser hat keine Ausgabe geliefert');
}

$parsed = json_decode($output, true);

if (!is_array($parsed)) {
    jsonOut(false, 'Parser-Fehler: ' . htmlspecialchars(substr($output, 0, 300)));
}

// Check for error key (single-object error response from parser)
if (isset($parsed['error'])) {
    jsonOut(false, 'Parser-Fehler: ' . htmlspecialchars($parsed['error']));
}

if (empty($parsed)) {
    jsonOut(true, 'Keine Events im PDF gefunden', ['imported' => 0, 'skipped' => 0]);
}

// ── Merge into events.json ────────────────────────────────────────────────────
$existing = loadJson($EVENTS_FILE);

// Build dedup key: "start|normalised_title"
function dedupKey(array $ev): string
{
    return substr($ev['start'], 0, 16) . '|' . mb_strtolower(trim($ev['title']));
}

$existingKeys = [];
foreach ($existing as $ev) {
    $existingKeys[dedupKey($ev)] = true;
}

$imported = 0;
$skipped  = 0;

foreach ($parsed as $ev) {
    if (!isset($ev['id'], $ev['title'], $ev['start'], $ev['end'])) {
        continue;
    }
    $key = dedupKey($ev);
    if (isset($existingKeys[$key])) {
        $skipped++;
        continue;
    }
    $existing[]          = $ev;
    $existingKeys[$key]  = true;
    $imported++;
}

// Sort by start date
usort($existing, fn($a, $b) => strcmp($a['start'], $b['start']));

if ($imported > 0 && !saveJson($EVENTS_FILE, $existing)) {
    jsonOut(false, 'events.json konnte nicht gespeichert werden');
}

// Refresh CSRF
$_SESSION['csrf'] = bin2hex(random_bytes(32));

jsonOut(true,
    "$imported Event(s) importiert" . ($skipped ? ", $skipped bereits vorhanden" : '') . '.',
    ['imported' => $imported, 'skipped' => $skipped, 'events' => $parsed]
);
