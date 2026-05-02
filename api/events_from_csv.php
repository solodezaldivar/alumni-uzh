<?php
declare(strict_types=1);

/**
 * Events API (CSV -> JSON)
 * - Display uses RAW CSV text (no formatting)
 * - Still supports upcoming filter (best-effort parse dd.mm.yyyy)
 * - Supports search (?q=...)
 */

header('Content-Type: application/json; charset=utf-8');

function tz(): DateTimeZone
{
    return new DateTimeZone('Europe/Zurich');
}

function out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    return preg_replace('~\s+~u', ' ', $s) ?? $s;
}

function resolveCsv(): ?string
{
    $candidates = [
        __DIR__ . '/../uploads/events/events.csv',
        __DIR__ . '/../uploads/events/events_2026.csv',
        __DIR__ . '/../../uploads/events/events.csv',
    ];
    foreach ($candidates as $p) {
        if (is_file($p) && filesize($p) > 0) return $p;
    }
    return null;
}

/**
 * Best-effort parse dd.mm.yyyy into DateTimeImmutable (for upcoming sort/filter only).
 * Returns null if not parseable.
 */
function parseDateText(string $dateText): ?DateTimeImmutable
{
    $dateText = trim($dateText);
    if ($dateText === '') return null;

    // Accept "28.01.2026" (with/without leading zeros)
    if (!preg_match('~^(\d{1,2})\.(\d{1,2})\.(\d{4})$~', $dateText, $m)) return null;

    $d = (int)$m[1];
    $mo = (int)$m[2];
    $y = (int)$m[3];

    // createFromFormat with ! to reset time
    $dt = DateTimeImmutable::createFromFormat('!d.m.Y', sprintf('%02d.%02d.%04d', $d, $mo, $y), tz());
    return $dt instanceof DateTimeImmutable ? $dt : null;
}

/** parse start time from "18:30-21:00" for sorting; returns minutes or null */
function parseStartMinutes(string $timeText): ?int
{
    $t = trim($timeText);
    if ($t === '') return null;
    $t = str_replace(['–', '—'], '-', $t);
    if (!preg_match('~^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$~', $t, $m)) return null;
    return ((int)$m[1]) * 60 + (int)$m[2];
}

function col(array $row, array $idx, array $names): string
{
    foreach ($names as $n) {
        $k = norm($n);
        if (isset($idx[$k]) && isset($row[$idx[$k]])) {
            return trim((string)$row[$idx[$k]]);
        }
    }
    return '';
}

$csvPath = resolveCsv();
if (!$csvPath) out(['ok' => false, 'error' => 'CSV nicht gefunden'], 404);

$upcoming = (string)($_GET['upcoming'] ?? '') === '1';
$query = norm((string)($_GET['q'] ?? ''));

$fh = fopen($csvPath, 'rb');
if (!$fh) out(['ok' => false, 'error' => 'CSV konnte nicht geöffnet werden'], 500);

// IMPORTANT: provide escape to avoid PHP 8.1+ deprecation
$header = fgetcsv($fh, 0, ';', '"', '\\');
if (!$header) {
    fclose($fh);
    out(['ok' => false, 'error' => 'CSV leer'], 400);
}

$idx = [];
foreach ($header as $i => $h) $idx[norm((string)$h)] = $i;

$today = new DateTimeImmutable('today', tz());
$todayTs = $today->getTimestamp();

$items = [];

while (($row = fgetcsv($fh, 0, ';', '"', '\\')) !== false) {
    $dateText = col($row, $idx, ['Datum', 'Date']);
    $timeText = col($row, $idx, ['Zeit', 'Time']);
    $title = col($row, $idx, ['Event', 'Titel', 'Title']);
    $loc = col($row, $idx, ['Ort', 'Location']);
    $notes = col($row, $idx, ['Bemerkungen', 'Notes']);

    if ($dateText === '' && $title === '' && $loc === '') continue;

    // upcoming filter: only if we can parse date. If not parseable, keep it (safer).
    $dt = parseDateText($dateText);
    if ($upcoming && $dt instanceof DateTimeImmutable) {
        if ($dt->getTimestamp() < $todayTs) continue;
    }

    // sort timestamp (best effort)
    $sortTs = $dt ? $dt->getTimestamp() : 0;
    $startMin = parseStartMinutes($timeText);
    if ($dt && $startMin !== null) $sortTs += $startMin * 60;

    // raw display (what you asked for)
    $datetimeText = trim($dateText);
    if (trim($timeText) !== '') $datetimeText .= ' · ' . trim($timeText);
    $datetimeText = trim($datetimeText);

    $hay = norm($dateText . ' ' . $timeText . ' ' . $title . ' ' . $loc . ' ' . $notes);
    if ($query !== '' && strpos($hay, $query) === false) continue;

    $items[] = [
        // preferred display field (single column)
        'datetime_text' => $datetimeText,

        // also include separate raw fields in case your UI still uses them
        'date_text' => $dateText,
        'time_text' => $timeText,

        'title' => $title,
        'location' => $loc,
        'notes' => $notes,

        // helpers
        'date_iso' => $dt ? $dt->format('Y-m-d') : null,
        'ts' => $sortTs,
    ];
}

fclose($fh);

// If we have timestamps, sort ascending; if not, keep stable order
usort($items, function ($a, $b) {
    return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0);
});

out([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
]);