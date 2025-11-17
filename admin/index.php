<?php
declare(strict_types=1);
session_start();

// CSRF token generation
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Admin · Event hinzufügen</title>
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
            <a class="active" href="/admin/" aria-current="page">Neuer Event</a>
            <a href="/admin/manage.php">Events verwalten</a>
            <a href="/" target="_blank" rel="noopener">Website öffnen</a>
        </nav>
    </div>
</header>

<section class="section">
    <div class="container">
        <h1>Event hinzufügen</h1>
        <form class="card"
              method="post"
              action="/api/add-event.php"
              enctype="multipart/form-data"
              novalidate
              style="display:grid; gap:16px; margin-top:12px;">

            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">

            <label>
                Titel <span class="req" aria-label="Pflichtfeld">*</span>
                <input class="input"
                       required
                       name="title"
                       maxlength="140"
                       placeholder="z.B. Alumni Apéro"
                       aria-required="true">
            </label>

            <div class="form-grid">
                <label>
                    Start <span class="req" aria-label="Pflichtfeld">*</span>
                    <input class="input"
                           required
                           type="datetime-local"
                           name="start"
                           aria-required="true">
                </label>
                <label>
                    Ende <span class="req" aria-label="Pflichtfeld">*</span>
                    <input class="input"
                           required
                           type="datetime-local"
                           name="end"
                           aria-required="true">
                </label>
            </div>

            <div class="form-grid">
                <label>
                    Ort
                    <input class="input"
                           name="location"
                           maxlength="140"
                           placeholder="UZH Zentrum, Raum XYZ">
                </label>
                <label>
                    Externe URL (Anmeldung/Infos)
                    <input class="input"
                           type="url"
                           name="url"
                           maxlength="500"
                           placeholder="https://…">
                </label>
            </div>

            <label>
                Tags (kommagetrennt)
                <input class="input"
                       name="tags"
                       maxlength="500"
                       placeholder="networking, talk, workshop">
            </label>

            <label>
                Bild (JPEG/PNG/WebP, ≤ 2 MB)
                <input class="input"
                       type="file"
                       name="image"
                       accept="image/jpeg,image/png,image/webp"
                       aria-describedby="image-hint">
                <span id="image-hint" class="hint">
                    Empfohlen: Querformat, min. 1200×900px.
                    Wird automatisch optimiert und verkleinert.
                </span>
            </label>

            <label>
                Beschreibung
                <textarea class="input"
                          name="description"
                          rows="6"
                          maxlength="5000"
                          placeholder="Kurzbeschreibung des Events …"></textarea>
            </label>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    Event speichern
                </button>
                <a class="btn btn-ghost" href="/admin/manage.php">
                    Zur Verwaltung
                </a>
            </div>
        </form>
    </div>
</section>
</body>
</html>