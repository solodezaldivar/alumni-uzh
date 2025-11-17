<?php
declare(strict_types=1);
session_start();

// CSRF token generation
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$EVENTS_FILE = __DIR__ . '/../events.json';
$TZ = new DateTimeZone('Europe/Zurich');

/**
 * Load events from JSON file
 */
function loadEvents(string $file): array
{
    if (!file_exists($file)) {
        file_put_contents($file, '[]', LOCK_EX);
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        return [];
    }

    flock($handle, LOCK_SH);
    $data = json_decode(fread($handle, filesize($file) ?: 1), true);
    flock($handle, LOCK_UN);
    fclose($handle);

    return is_array($data) ? $data : [];
}

/**
 * Save events to JSON file atomically
 */
function saveEvents(string $file, array $events): void
{
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
    file_put_contents($tmp, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $file);
}

/**
 * Convert ISO datetime to local datetime-local format
 */
function isoLocal(?string $s, DateTimeZone $tz): string
{
    if (!$s) return '';

    try {
        $dt = new DateTime($s);
        $dt->setTimezone($tz);
        return $dt->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Convert local datetime-local to ISO format
 */
function localToIso(?string $local, DateTimeZone $tz): ?string
{
    if (!$local || trim($local) === '') {
        return null;
    }

    try {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $local, $tz);
        return $dt ? $dt->format(DateTime::ATOM) : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Validate and sanitize input
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
 * Validate URL format
 */
function isValidUrl(?string $url): bool
{
    if (!$url || trim($url) === '') {
        return true; // Empty is valid (optional field)
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Validate event ID format (prevent directory traversal)
 */
function isValidEventId(string $id): bool
{
    return preg_match('/^evt_[\w\-]+$/', $id) === 1;
}

$events = loadEvents($EVENTS_FILE);
$error = '';
$success = '';

// Handle POST (update / delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation - FIXED
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        die('Ungültiges CSRF-Token');
    }

    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? '';

    // Validate event ID
    if (!isValidEventId($id)) {
        http_response_code(400);
        die('Ungültige Event-ID');
    }

    if ($action === 'delete') {
        $events = array_values(array_filter($events, fn($e) => $e['id'] !== $id));
        saveEvents($EVENTS_FILE, $events);
        header('Location: manage.php?msg=deleted');
        exit;
    }

    if ($action === 'update') {
        $updated = false;

        foreach ($events as &$e) {
            if ($e['id'] === $id) {
                // Validate and sanitize inputs
                $title = sanitizeInput($_POST['title'] ?? '', 140);
                $location = sanitizeInput($_POST['location'] ?? '', 140);
                $url = sanitizeInput($_POST['url'] ?? '', 500);
                $description = sanitizeInput($_POST['description'] ?? '', 5000);
                $tagsRaw = sanitizeInput($_POST['tags'] ?? '', 500);

                // Validate title is not empty
                if (empty($title)) {
                    $error = 'Titel darf nicht leer sein';
                    break;
                }

                // Validate URL format
                if (!isValidUrl($url)) {
                    $error = 'Ungültiges URL-Format';
                    break;
                }

                // Parse and validate dates
                $start = localToIso($_POST['start'] ?? '', $TZ);
                $end = localToIso($_POST['end'] ?? '', $TZ);

                if (!$start) {
                    $error = 'Ungültiges Startdatum';
                    break;
                }

                // Validate end is after start
                if ($end && $start && $end < $start) {
                    $error = 'Enddatum muss nach Startdatum liegen';
                    break;
                }

                // Parse tags
                $tags = array_values(array_filter(
                    array_map('trim', explode(',', $tagsRaw)),
                    fn($t) => !empty($t) && mb_strlen($t) <= 50
                ));

                // Update event
                $e['title'] = $title;
                $e['location'] = $location;
                $e['url'] = $url;
                $e['description'] = $description;
                $e['tags'] = $tags;
                $e['start'] = $start;
                $e['end'] = $end;
                $e['updatedAt'] = (new DateTime())->format(DateTime::ATOM);

                $updated = true;
                break;
            }
        }
        unset($e);

        if (!$error && $updated) {
            // Re-sort by start date
            usort($events, fn($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));
            saveEvents($EVENTS_FILE, $events);
            header('Location: manage.php?msg=updated');
            exit;
        } elseif (!$updated && !$error) {
            $error = 'Event nicht gefunden';
        }
    }
}

// Handle success messages
if (!empty($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'deleted':
            $success = 'Event erfolgreich gelöscht';
            break;
        case 'updated':
            $success = 'Event erfolgreich aktualisiert';
            break;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Admin · Events verwalten</title>
    <link rel="stylesheet" href="/styles.css?v=20251117"/>
</head>
<body class="theme-quartz-ivory admin">
<header class="site-header admin-header">
    <div class="container header-inner">
        <div class="brand-group">
            <a class="brand" href="/" aria-label="Startseite">
                <img src="/uploads/alumni_logo_header.png" alt="Alumni Informatik" height="32">
            </a>
            <strong>Admin</strong>
        </div>
        <nav class="admin-nav">
            <a href="/admin/">Neuer Event</a>
            <a href="/admin/manage.php" class="active" aria-current="page">Events verwalten</a>
            <a href="/" target="_blank" rel="noopener">Website öffnen</a>
        </nav>
    </div>
</header>

<section class="section">
    <div class="container admin-container">
        <h1>Events verwalten</h1>

        <?php if ($error): ?>
            <div class="flash flash-error" role="alert">
                <strong>Fehler:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="flash flash-success" role="status">
                <strong>Erfolg:</strong> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($events)): ?>
            <p class="muted">
                Noch keine Events vorhanden.
                <a href="/admin/">Jetzt einen Event hinzufügen</a>.
            </p>
        <?php else: ?>
            <p class="muted">
                <?= count($events) ?> Event<?= count($events) === 1 ? '' : 's' ?> vorhanden
            </p>
        <?php endif; ?>

        <?php foreach ($events as $e):
            $startLocal = isoLocal($e['start'] ?? '', $TZ);
            $endLocal = isoLocal($e['end'] ?? '', $TZ);

            try {
                $meta = $e['start']
                    ? (new DateTime($e['start']))->format('d.m.Y H:i')
                    : 'ohne Datum';
            } catch (Exception $ex) {
                $meta = 'ungültiges Datum';
            }
            ?>
            <div class="event-item">
                <details class="card">
                    <summary>
                        <span class="summary-title">
                            <?= htmlspecialchars($e['title'] ?? 'Unbenannt') ?>
                        </span>
                        <span class="summary-meta">
                            <?= htmlspecialchars($meta) ?>
                            <?= !empty($e['location']) ? ' · ' . htmlspecialchars($e['location']) : '' ?>
                        </span>
                    </summary>

                    <form class="edit-form" method="post" novalidate>
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($e['id']) ?>">

                        <div class="form-grid">
                            <label>
                                Titel <span class="req" aria-label="Pflichtfeld">*</span>
                                <input name="title"
                                       value="<?= htmlspecialchars($e['title'] ?? '') ?>"
                                       maxlength="140"
                                       required
                                       aria-required="true">
                            </label>
                            <label>
                                Ort
                                <input name="location"
                                       value="<?= htmlspecialchars($e['location'] ?? '') ?>"
                                       maxlength="140">
                            </label>
                        </div>

                        <div class="form-grid">
                            <label>
                                Start (lokal) <span class="req" aria-label="Pflichtfeld">*</span>
                                <input type="datetime-local"
                                       name="start"
                                       value="<?= $startLocal ?>"
                                       required
                                       aria-required="true">
                            </label>
                            <label>
                                Ende (lokal)
                                <input type="datetime-local"
                                       name="end"
                                       value="<?= $endLocal ?>">
                            </label>
                        </div>

                        <label>
                            Externe URL
                            <input type="url"
                                   name="url"
                                   value="<?= htmlspecialchars($e['url'] ?? '') ?>"
                                   maxlength="500"
                                   placeholder="https://...">
                        </label>

                        <label>
                            Tags (kommagetrennt)
                            <input name="tags"
                                   value="<?= htmlspecialchars(implode(', ', $e['tags'] ?? [])) ?>"
                                   maxlength="500"
                                   placeholder="networking, talk, workshop">
                        </label>

                        <label>
                            Beschreibung
                            <textarea name="description"
                                      rows="5"
                                      maxlength="5000"><?= htmlspecialchars($e['description'] ?? '') ?></textarea>
                        </label>

                        <?php if (!empty($e['image'])): ?>
                            <div class="current-image">
                                <strong>Aktuelles Bild:</strong>
                                <img src="<?= htmlspecialchars($e['image']) ?>"
                                     alt="Event-Bild"
                                     loading="lazy"
                                     style="max-width: 200px; border-radius: 8px; margin-top: 8px;">
                            </div>
                        <?php endif; ?>

                        <div class="actions">
                            <button class="btn btn-primary"
                                    type="submit"
                                    name="action"
                                    value="update">
                                Speichern
                            </button>
                            <button class="btn btn-danger"
                                    type="submit"
                                    name="action"
                                    value="delete"
                                    onclick="return confirm('Diesen Event wirklich löschen?')"
                                    aria-label="Event <?= htmlspecialchars($e['title'] ?? 'Unbenannt') ?> löschen">
                                Löschen
                            </button>
                        </div>
                    </form>
                </details>
            </div>
        <?php endforeach; ?>
    </div>
</section>
</body>
</html>