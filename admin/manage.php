<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

$EVENTS_FILE = __DIR__ . '/../events.json';
$TZ = new DateTimeZone('Europe/Zurich');

function loadEvents($file) {
    if (!file_exists($file)) file_put_contents($file, '[]', LOCK_EX);
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function saveEvents($file, $events) {
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($events, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $file);
}
function isoLocal($s, $tz) {
    if (!$s) return '';
    $dt = new DateTime($s);
    $dt->setTimezone($tz);
    return $dt->format('Y-m-d\TH:i');
}

$events = loadEvents($EVENTS_FILE);

// Handle POST (update/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(400); die('Bad CSRF');
    }
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? '';
    if ($action === 'delete') {
        $events = array_values(array_filter($events, fn($e) => $e['id'] !== $id));
        saveEvents($EVENTS_FILE, $events);
        header('Location: manage.php?msg=deleted'); exit;
    }
    if ($action === 'update') {
        foreach ($events as &$e) {
            if ($e['id'] === $id) {
                $e['title'] = trim($_POST['title'] ?? $e['title']);
                $e['location'] = trim($_POST['location'] ?? '');
                $e['url'] = trim($_POST['url'] ?? '');
                $e['description'] = trim($_POST['description'] ?? '');
                $e['tags'] = array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))));
                // Convert local datetimes back to ISO with timezone
                $toIso = function($local) use ($TZ) {
                    if (!$local) return null;
                    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $local, $TZ);
                    return $dt ? $dt->format(DateTime::ATOM) : null;
                };
                $e['start'] = $toIso($_POST['start']) ?? $e['start'];
                $e['end']   = $toIso($_POST['end']) ?: null;
            }
        }
        unset($e);
        // Sort by start
        usort($events, fn($a,$b) => strcmp($a['start'],$b['start']));
        saveEvents($EVENTS_FILE, $events);
        header('Location: manage.php?msg=updated'); exit;
    }
}
?>
<!doctype html>
<html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage Events</title>
    <style>
        body { max-width: 1100px; margin: 2rem auto; font-family: system-ui, sans-serif; padding: 0 1rem; }
        details { border:1px solid #e5e5e5; border-radius: 12px; padding: .8rem 1rem; margin-bottom: 12px; }
        summary { font-weight: 700; cursor: pointer; }
        form { display: grid; gap: 8px; margin-top: 10px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        input, textarea { padding:.55rem .6rem; border:1px solid #ddd; border-radius:8px; }
        .actions { display:flex; gap:8px; }
        .danger { background:#b00020; color:#fff; border:none; padding:.5rem .8rem; border-radius:8px; }
        .primary { background:#0b5; color:#fff; border:none; padding:.5rem .8rem; border-radius:8px; }
    </style>
</head><body>
<h1>Manage Events</h1>
<p><a href="/admin/index.php">➕ Add new</a></p>
<?php if (!empty($_GET['msg'])) echo "<p><strong>".$_GET['msg']."</strong></p>"; ?>

<?php foreach ($events as $e): ?>
    <details>
        <summary><?= htmlspecialchars($e['title']) ?> — <small><?= htmlspecialchars($e['id']) ?></small></summary>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
            <input type="hidden" name="id" value="<?= htmlspecialchars($e['id']) ?>">
            <div class="row">
                <label>Title<input name="title" value="<?= htmlspecialchars($e['title']) ?>"></label>
                <label>Location<input name="location" value="<?= htmlspecialchars($e['location'] ?? '') ?>"></label>
            </div>
            <div class="row">
                <label>Start (local)<input type="datetime-local" name="start" value="<?= isoLocal($e['start'], $TZ) ?>"></label>
                <label>End (local)<input type="datetime-local" name="end" value="<?= isoLocal($e['end'] ?? '', $TZ) ?>"></label>
            </div>
            <label>External URL<input name="url" value="<?= htmlspecialchars($e['url'] ?? '') ?>"></label>
            <label>Tags (comma-separated)<input name="tags" value="<?= htmlspecialchars(implode(', ', $e['tags'] ?? [])) ?>"></label>
            <label>Description<textarea name="description" rows="5"><?= htmlspecialchars($e['description'] ?? '') ?></textarea></label>
            <div class="actions">
                <button class="primary" type="submit" name="action" value="update">Save changes</button>
                <button class="danger" type="submit" name="action" value="delete" onclick="return confirm('Delete this event?')">Delete</button>
            </div>
        </form>
    </details>
<?php endforeach; ?>
</body></html>
