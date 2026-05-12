<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

$EVENTS_FILE = __DIR__ . '/../events.json';

function jsonOut(bool $ok, string $msg = '', array $extra = []): never
{
    echo json_encode(
        array_merge(['ok' => $ok, 'message' => $msg], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function loadJson(string $path): array
{
    if (!file_exists($path)) return [];
    $h = fopen($path, 'r');
    if (!$h) return [];
    flock($h, LOCK_SH);
    $d = json_decode(fread($h, filesize($path) ?: 1), true);
    flock($h, LOCK_UN);
    fclose($h);
    return is_array($d) ? $d : [];
}

function saveJson(string $path, array $data): bool
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    $ok  = file_put_contents(
        $tmp,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
    return $ok && rename($tmp, $path);
}

function dedupKey(array $ev): string
{
    return substr($ev['start'] ?? '', 0, 16) . '|' . mb_strtolower(trim($ev['title'] ?? ''));
}

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(false, 'POST required');
}

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    jsonOut(false, 'Ungültiges CSRF-Token');
}

// Accept pre-parsed events JSON from the browser-side PDF.js parser
$raw = trim((string) ($_POST['events_json'] ?? ''));
if ($raw === '') {
    jsonOut(false, 'Keine Events-Daten empfangen');
}

$parsed = json_decode($raw, true);
if (!is_array($parsed)) {
    jsonOut(false, 'Ungültiges JSON-Format');
}

if (empty($parsed)) {
    jsonOut(true, 'Keine Events zum Importieren', ['imported' => 0, 'skipped' => 0]);
}

// Validate each event has the required fields
$clean = [];
foreach ($parsed as $ev) {
    if (!is_array($ev)) continue;
    if (empty($ev['title']) || empty($ev['start']) || empty($ev['end'])) continue;
    // Ensure safe id
    if (empty($ev['id']) || !preg_match('/^evt_[\w\-]+$/', $ev['id'])) {
        $ev['id'] = 'evt_' . substr(preg_replace('/[^0-9]/', '', $ev['start']), 0, 8)
                  . '_' . bin2hex(random_bytes(4));
    }
    $clean[] = $ev;
}

if (empty($clean)) {
    jsonOut(false, 'Keine gültigen Events in den Daten gefunden');
}

// Merge into events.json, deduplicating by start-time + title
$existing     = loadJson($EVENTS_FILE);
$existingKeys = [];
foreach ($existing as $ev) {
    $existingKeys[dedupKey($ev)] = true;
}

$imported = 0;
$skipped  = 0;

foreach ($clean as $ev) {
    $key = dedupKey($ev);
    if (isset($existingKeys[$key])) {
        $skipped++;
        continue;
    }
    $existing[]          = $ev;
    $existingKeys[$key]  = true;
    $imported++;
}

usort($existing, fn($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));

if ($imported > 0 && !saveJson($EVENTS_FILE, $existing)) {
    jsonOut(false, 'events.json konnte nicht gespeichert werden');
}

$_SESSION['csrf'] = bin2hex(random_bytes(32));

$msg = "$imported Event(s) importiert" . ($skipped ? ", $skipped bereits vorhanden" : '') . '.';
jsonOut(true, $msg, ['imported' => $imported, 'skipped' => $skipped]);
