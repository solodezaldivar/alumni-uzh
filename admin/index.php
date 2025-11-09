<?php
// Start session for CSRF token
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Add Event</title>
    <link rel="stylesheet" href="/styles.css" />
    <style>
        body { max-width: 720px; margin: 2rem auto; font-family: system-ui, sans-serif; }
        form { display: grid; gap: 12px; }
        label { font-weight: 600; }
        input, textarea { padding: 8px; }
        .row { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
    </style>
</head>
<body>
<h1>Add Event</h1>
<form method="post" action="/api/add-event.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
    <label>Title
        <input required name="title" maxlength="140" />
    </label>
    <div class="row">
        <label>Start (local)
            <input required type="datetime-local" name="start" />
        </label>
        <label>End (local)
            <input type="datetime-local" name="end" />
        </label>
    </div>
    <label>Location
        <input name="location" maxlength="140" />
    </label>
    <label>External URL (e.g., signup)
        <input type="url" name="url" />
    </label>
    <label>Tags (comma-separated)
        <input name="tags" placeholder="networking, talk" />
    </label>
    <label>Image (JPEG/PNG/WebP, ≤ 2 MB)
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" />
    </label>
    <label>Description
        <textarea name="description" rows="6"></textarea>
    </label>
    <button type="submit">Save event</button>
</form>
</body>
</html>
