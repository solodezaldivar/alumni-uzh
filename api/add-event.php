<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

// --- CONFIG ---
$EVENTS_FILE = __DIR__ . '/../events.json';
$UPLOAD_DIR  = __DIR__ . '/../uploads';
$BASE_URL    = '/uploads'; // public path
$MAX_IMAGE_BYTES = 2 * 1024 * 1024; // 2 MB
$ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$TZ = new DateTimeZone('Europe/Zurich');

// --- CSRF ---
if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
  exit;
}

// --- AUTH (optional extra check) ---
// If /admin is already Basic-Auth protected, this is optional. Uncomment if you want hard-fail when no auth headers.
// if (empty($_SERVER['PHP_AUTH_USER'])) { http_response_code(401); exit; }

// --- INPUT ---
$title = trim((string)($_POST['title'] ?? ''));
$startLocal = trim((string)($_POST['start'] ?? ''));
$endLocal = trim((string)($_POST['end'] ?? ''));
$location = trim((string)($_POST['location'] ?? ''));
$url = trim((string)($_POST['url'] ?? ''));
$tags = array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? ''))));
$description = trim((string)($_POST['description'] ?? ''));

// Validate required fields
if ($title === '' || $startLocal === '') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'title and start are required']);
  exit;
}

// Convert local datetime (YYYY-MM-DDTHH:MM) to ISO with timezone
function localToIso(string $local, DateTimeZone $tz): string {
  $dt = DateTime::createFromFormat('Y-m-d\TH:i', $local, $tz);
  if (!$dt) { throw new RuntimeException('Invalid datetime'); }
  return $dt->format(DateTime::ATOM); // ISO 8601 with offset
}

try {
  $startIso = localToIso($startLocal, $TZ);
  $endIso = $endLocal !== '' ? localToIso($endLocal, $TZ) : null;
} catch (Throwable $e) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid date format']);
  exit;
}

// URL sanity
if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
  exit;
}

// --- IMAGE UPLOAD (optional) ---
$imagePublicPath = null;
if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
  if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Upload error']);
    exit;
  }
  if ($_FILES['image']['size'] > $MAX_IMAGE_BYTES) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Image too large']);
    exit;
  }
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($_FILES['image']['tmp_name']);
  if (!isset($ALLOWED_MIME[$mime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Unsupported image type']);
    exit;
  }
  if (!is_dir($UPLOAD_DIR)) {
    if (!mkdir($UPLOAD_DIR, 0755, true)) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Upload dir not writable']);
      exit;
    }
  }
  // Generate a safe filename
  $slug = preg_replace('~[^a-z0-9]+~i', '-', strtolower($title));
  $datePart = substr($startIso, 0, 10);
  $ext = $ALLOWED_MIME[$mime];
  $filename = sprintf('%s-%s-%s.%s', $datePart, $slug, bin2hex(random_bytes(4)), $ext);
  $dest = $UPLOAD_DIR . '/' . $filename;
  if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save image']);
    exit;
  }
  $imagePublicPath = $BASE_URL . '/' . $filename;
}

// --- LOAD EXISTING JSON ---
if (!file_exists($EVENTS_FILE)) {
  file_put_contents($EVENTS_FILE, '[]', LOCK_EX);
}
$json = file_get_contents($EVENTS_FILE);
$events = json_decode($json, true);
if (!is_array($events)) { $events = []; }

// --- BUILD EVENT ---
$id = 'evt_' . substr($startIso, 0, 10) . '_' . substr(preg_replace('~[^a-z0-9]+~i', '-', strtolower($title)), 0, 32);

$event = [
  'id'          => $id,
  'title'       => $title,
  'start'       => $startIso,
  'end'         => $endIso,
  'location'    => $location !== '' ? $location : null,
  'image'       => $imagePublicPath,
  'url'         => $url !== '' ? $url : null,
  'description' => $description !== '' ? $description : null,
  'tags'        => array_values($tags),
  'createdAt'   => (new DateTime('now', $TZ))->format(DateTime::ATOM),
];

// --- APPEND & SORT (by start asc) ---
$events[] = $event;
usort($events, function ($a, $b) {
  return strcmp($a['start'], $b['start']);
});

// --- WRITE ATOMICALLY ---
$tmp = $EVENTS_FILE . '.tmp';
file_put_contents($tmp, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
rename($tmp, $EVENTS_FILE);

// --- DONE ---
echo json_encode(['ok' => true, 'event' => $event]);
