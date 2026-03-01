<?php
/**
 * Admin (Readme upload: PDF + JPG required)
 *
 * Saves both files into: /readme/archive/
 * Also updates stable pointers:
 *   /readme/archive/latest.pdf
 *   /readme/archive/latest.jpg
 *
 * The public website should link to: readme/archive/latest.pdf
 */

declare(strict_types=1);
session_start();

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ---- Configure archive dir (adjust if needed) ----
// Typical:
//  /admin/index.php
//  /readme/archive/*.pdf + *.jpg
$archiveDir =
    realpath(__DIR__ . '/../readme/archive')        // admin/ sibling readme/
        ?: realpath(__DIR__ . '/../../readme/archive'); // fallback if admin/ is deeper

if (!$archiveDir || !is_dir($archiveDir)) {
    http_response_code(500);
    echo "Archive folder not found. Please set \$archiveDir correctly.";
    exit;
}

$ok = null;
$err = null;

function safe_base(string $name): string
{
    $name = preg_replace('~\.[a-zA-Z0-9]+$~', '', $name);
    $name = preg_replace('~[^a-zA-Z0-9._-]+~', '_', $name);
    $name = trim($name, '_');
    return $name ?: ('readme_' . date('Ymd_His'));
}

function require_upload(string $key): array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        throw new RuntimeException("Upload fehlt: {$key} (keine Datei empfangen)");
    }

    $f = $_FILES[$key];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE => 'Datei zu gross (upload_max_filesize in php.ini).',
            UPLOAD_ERR_FORM_SIZE => 'Datei zu gross (MAX_FILE_SIZE im Formular).',
            UPLOAD_ERR_PARTIAL => 'Datei nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_FILE => 'Keine Datei ausgewählt.',
            UPLOAD_ERR_NO_TMP_DIR => 'Fehlender temporärer Ordner (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'Konnte Datei nicht auf Disk schreiben (Rechte?).',
            UPLOAD_ERR_EXTENSION => 'Upload durch PHP-Extension gestoppt.',
        ];
        $msg = $map[$err] ?? ('Unbekannter Upload-Fehler: ' . $err);

        // helpful context
        $name = (string)($f['name'] ?? '');
        $size = (int)($f['size'] ?? 0);

        throw new RuntimeException("Upload fehlgeschlagen: {$key} — {$msg} (name={$name}, size={$size} bytes)");
    }

    return $f;
}

function assert_ext(string $filename, array $allowed): void
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException("Ungültiger Dateityp: {$filename}");
    }
}

function is_pdf(string $tmp): bool
{
    $fh = @fopen($tmp, 'rb');
    if (!$fh) return false;
    $head = fread($fh, 1024);
    fclose($fh);
    if ($head === false) return false;
    return (strpos($head, '%PDF') !== false);
}

function is_jpeg_or_png(string $tmp): bool
{
    $info = @getimagesize($tmp);
    if (!$info) return false;
    return in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Ungültige Sitzung (CSRF). Bitte Seite neu laden.');
        }

        $pdf = require_upload('readme_pdf');
        $img = require_upload('readme_jpg');

        // basic extension checks
        assert_ext((string)$pdf['name'], ['pdf']);
        assert_ext((string)$img['name'], ['jpg', 'jpeg', 'png']);

        // content checks
        if (!is_pdf((string)$pdf['tmp_name'])) {
            throw new RuntimeException('Die PDF ist ungültig (kein PDF-Header gefunden).');
        }
        if (!is_jpeg_or_png((string)$img['tmp_name'])) {
            throw new RuntimeException('Das Bild ist ungültig (kein gültiges JPG/PNG).');
        }

        // Determine base name (prefer pdf name base)
        $base = safe_base((string)$pdf['name']);

        // Avoid overwriting existing archive entries
        $pdfTarget = $archiveDir . '/' . $base . '.pdf';
        $imgExt = strtolower(pathinfo((string)$img['name'], PATHINFO_EXTENSION));
        if ($imgExt === 'jpeg') $imgExt = 'jpg';
        $imgTarget = $archiveDir . '/' . $base . '.' . $imgExt;

        if (file_exists($pdfTarget) || file_exists($imgTarget)) {
            $base = $base . '__' . date('Ymd_His');
            $pdfTarget = $archiveDir . '/' . $base . '.pdf';
            $imgTarget = $archiveDir . '/' . $base . '.' . $imgExt;
        }

        // Move uploads into archive
        if (!move_uploaded_file((string)$pdf['tmp_name'], $pdfTarget)) {
            throw new RuntimeException('Konnte PDF nicht speichern (Rechte?).');
        }
        if (!move_uploaded_file((string)$img['tmp_name'], $imgTarget)) {
            @unlink($pdfTarget);
            throw new RuntimeException('Konnte Bild nicht speichern (Rechte?).');
        }

        // Update stable latest pointers
        $latestPdf = $archiveDir . '/latest.pdf';
        $latestJpg = $archiveDir . '/latest.jpg';

        if (!@copy($pdfTarget, $latestPdf)) {
            throw new RuntimeException('Konnte latest.pdf nicht aktualisieren.');
        }

        // Convert png to jpg? (optional). For now: if png, store as latest.jpg via re-encode, else copy.
        if ($imgExt === 'png') {
            $im = @imagecreatefrompng($imgTarget);
            if (!$im) throw new RuntimeException('Konnte PNG nicht verarbeiten.');
            imagejpeg($im, $latestJpg, 88);
            imagedestroy($im);
        } else {
            if (!@copy($imgTarget, $latestJpg)) {
                throw new RuntimeException('Konnte latest.jpg nicht aktualisieren.');
            }
        }

        $ok = 'Upload erfolgreich. Website zeigt jetzt automatisch die neueste Ausgabe (latest.pdf).';
        // refresh CSRF
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// Public links
$publicLatestPdf = '../readme/archive/latest.pdf';
$publicLatestJpg = '../readme/archive/latest.jpg';

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Admin · Readme Upload · UZH Alumni Informatik</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../styles.css?v=20251117"/>

    <style>
        /* Admin page that matches the main site design language */
        .admin-shell {
            max-width: 920px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .admin-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .admin-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 999px;
            background: rgba(29, 78, 216, .06);
            border: 1px solid rgba(29, 78, 216, .14);
            font-weight: 700;
        }

        .badge small {
            color: rgba(91, 98, 115, .92);
            font-weight: 650;
        }

        .notice {
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, .10);
            background: rgba(255, 255, 255, .9);
        }

        .notice.ok {
            border-color: rgba(5, 150, 105, .35);
            background: rgba(5, 150, 105, .06);
        }

        .notice.err {
            border-color: rgba(220, 38, 38, .35);
            background: rgba(220, 38, 38, .06);
        }

        .admin-form {
            display: grid;
            gap: 14px;
        }

        .admin-form label {
            display: grid;
            gap: 8px;
            font-weight: 750;
            letter-spacing: -0.01em;
        }

        .help {
            margin: 0;
            color: rgba(91, 98, 115, .92);
            font-weight: 550;
        }

        .admin-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 6px;
        }

        .file {
            padding: .85rem 1rem;
            border-radius: 14px;
            border: 1px dashed rgba(15, 23, 42, .18);
            background: linear-gradient(180deg, rgba(248, 250, 252, .92), rgba(255, 255, 255, .92));
        }

        .file input {
            width: 100%;
        }
    </style>
</head>
<body class="theme-quartz-ivory">

<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="../index.html#top" aria-label="Zur Website">
            <img src="../uploads/logos/alumni_logo_header.png" alt="UZH Alumni Informatik - Alumni.ch">
        </a>

        <nav class="nav" style="margin-left:auto">
            <a href="../index.html#readme">Website</a>
            <a href="../readme-archiv.php" class="btn btn-ghost">Archiv</a>
            <a href="../index.html#contact" class="btn btn-primary">Kontakt</a>
        </nav>
    </div>
</header>

<main class="admin-shell">
    <div class="admin-head">
        <div>
            <h2 style="margin:0 0 6px">Admin · Readme Upload</h2>
        </div>
    </div>

    <div style="height:14px"></div>

    <?php if ($ok): ?>
        <div class="notice ok" role="status"><?= h($ok) ?></div>
        <div style="height:10px"></div>
    <?php endif; ?>

    <?php if ($err): ?>
        <div class="notice err" role="alert"><?= h($err) ?></div>
        <div style="height:10px"></div>
    <?php endif; ?>

    <section class="card" style="padding:26px;border-radius:22px">
        <form method="post" class="admin-form" enctype="multipart/form-data" autocomplete="off" novalidate>
            <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">

            <label class="file">
                Readme PDF (required)
                <input type="file" name="readme_pdf" accept="application/pdf,.pdf" required>
            </label>

            <label class="file">
                Cover JPG/PNG (required)
                <input type="file" name="readme_jpg" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required>
            </label>

            <div class="admin-actions">
                <button class="btn btn-primary" type="submit">Upload & Publish</button>
            </div>
        </form>
    </section>
</main>

</body>
</html>