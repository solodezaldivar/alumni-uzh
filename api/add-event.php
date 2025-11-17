<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

// Enable error logging but don't display errors in production
ini_set('display_errors', '0');
error_reporting(E_ALL);

$EVENTS_FILE = __DIR__ . '/../events.json';
$UPLOAD_DIR = __DIR__ . '/../uploads';
$TZ = new DateTimeZone('Europe/Zurich');
$MAX_FILE_SIZE = 2 * 1024 * 1024; // 2MB
$ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Send JSON response and exit
 */
function jsonResponse(bool $ok, string $message = '', array $data = []): void
{
    $payload = array_merge([
        'ok' => $ok,
        'error' => $ok ? '' : $message,
        'message' => $message,
        'csrf' => $_SESSION['csrf'] ?? null,
    ], $data);

    echo json_encode($payload);
    exit;
}

/**
 * Validate CSRF token
 */
function validateCsrf(): bool
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    if (empty($_POST['csrf'])) {
        return false;
    }

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return false;
    }

    return true;
}

/**
 * Load events from JSON file with locking
 */
function loadEvents(string $file): array
{
    if (!file_exists($file)) {
        file_put_contents($file, '[]', LOCK_EX);
        return [];
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        return [];
    }

    flock($handle, LOCK_SH);
    $content = fread($handle, filesize($file) ?: 1);
    $data = json_decode($content, true);
    flock($handle, LOCK_UN);
    fclose($handle);

    return is_array($data) ? $data : [];
}

/**
 * Save events atomically
 */
function saveEvents(string $file, array $events): bool
{
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
    $json = json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, $file);
}

/**
 * Validate and sanitize string input
 */
function sanitizeInput(string $input, int $maxLength = 0): string
{
    $input = trim($input);
    if ($maxLength > 0 && mb_strlen($input) > $maxLength) {
        $input = mb_substr($input, 0, $maxLength);
    }
    return $input;
}

/**
 * Validate URL
 */
function isValidUrl(?string $url): bool
{
    if (!$url || trim($url) === '') {
        return true;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Convert local datetime to ISO format
 */
function localToIso(string $local, DateTimeZone $tz): ?string
{
    try {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $local, $tz);
        return $dt ? $dt->format(DateTime::ATOM) : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Generate safe filename
 */
function safeFilename(string $title, string $extension): string
{
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    $slug = substr($slug, 0, 50);
    $hash = substr(bin2hex(random_bytes(4)), 0, 8);
    return $slug . '-' . $hash . '.' . $extension;
}

/**
 * Process and optimize uploaded image
 */
function processImage(array $file, string $uploadDir): ?string
{
    global $MAX_FILE_SIZE, $ALLOWED_TYPES;

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    // Check file size
    if ($file['size'] > $MAX_FILE_SIZE) {
        return null;
    }

    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $ALLOWED_TYPES)) {
        return null;
    }

    // Determine extension
    $extension = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => null
    };

    if (!$extension) {
        return null;
    }

    // Generate safe filename
    $filename = safeFilename($_POST['title'] ?? 'event', $extension);
    $targetPath = $uploadDir . '/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return null;
    }

    // Optional: Optimize image (requires GD or ImageMagick)
    // This is a placeholder - implement based on your server capabilities

    return '/uploads/' . $filename;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Ungültige Anfragemethode');
}

// Validate CSRF
if (!validateCsrf()) {
    jsonResponse(false, 'Ungültiges CSRF-Token');
}

// Validate required fields
$title = sanitizeInput($_POST['title'] ?? '', 140);
$start = sanitizeInput($_POST['start'] ?? '', 50);
$end = sanitizeInput($_POST['end'] ?? '', 50);

if (empty($title)) {
    jsonResponse(false, 'Titel ist erforderlich');
}

if (empty($start)) {
    jsonResponse(false, 'Startdatum ist erforderlich');
}

if (empty($end)) {
    jsonResponse(false, 'Enddatum ist erforderlich');
}

// Convert dates
$startIso = localToIso($start, $TZ);
$endIso = localToIso($end, $TZ);

if (!$startIso) {
    jsonResponse(false, 'Ungültiges Startdatum');
}

if (!$endIso) {
    jsonResponse(false, 'Ungültiges Enddatum');
}

// Validate end is after start
if ($endIso <= $startIso) {
    jsonResponse(false, 'Enddatum muss nach Startdatum liegen');
}

// Validate optional fields
$location = sanitizeInput($_POST['location'] ?? '', 140);
$url = sanitizeInput($_POST['url'] ?? '', 500);
$description = sanitizeInput($_POST['description'] ?? '', 5000);
$tagsRaw = sanitizeInput($_POST['tags'] ?? '', 500);

if (!isValidUrl($url)) {
    jsonResponse(false, 'Ungültiges URL-Format');
}

// Parse tags
$tags = array_values(array_filter(
    array_map('trim', explode(',', $tagsRaw)),
    fn($t) => !empty($t) && mb_strlen($t) <= 50
));

// Process image if provided
$imagePath = null;
if (!empty($_FILES['image']['name'])) {
    $imagePath = processImage($_FILES['image'], $UPLOAD_DIR);
    if (!$imagePath) {
        jsonResponse(false, 'Bild konnte nicht hochgeladen werden (max. 2 MB, nur JPG/PNG/WebP)');
    }
}

// Generate event ID
$eventId = 'evt_' . (new DateTime($startIso))->format('Y-m-d') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

// Create event object
$event = [
    'id' => $eventId,
    'title' => $title,
    'start' => $startIso,
    'end' => $endIso,
    'location' => $location,
    'image' => $imagePath,
    'url' => $url ?: null,
    'description' => $description ?: null,
    'tags' => $tags,
    'createdAt' => (new DateTime())->format(DateTime::ATOM)
];

// Load existing events
$events = loadEvents($EVENTS_FILE);

// Add new event
$events[] = $event;

// Sort by start date
usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));

// Save events
if (!saveEvents($EVENTS_FILE, $events)) {
    jsonResponse(false, 'Event konnte nicht gespeichert werden');
}

// Regenerate CSRF token for security
$_SESSION['csrf'] = bin2hex(random_bytes(32));

jsonResponse(true, 'Event erfolgreich erstellt', ['eventId' => $eventId]);
