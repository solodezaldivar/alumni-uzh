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
            <img src="../uploads/logos/uzhai_banner_logo_ohne_hintergrund.png" alt="UZH Alumni Informatik - Alumni.ch">
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

    <div style="height:32px"></div>

    <!-- ── Events PDF Import ──────────────────────────────────────────── -->
    <div class="admin-head">
        <div>
            <h2 style="margin:0 0 6px">Events Import</h2>
            <p style="margin:0;color:rgba(91,98,115,.92);font-size:.95rem">
                Jahresprogramm-PDF hochladen – Events werden direkt im Browser erkannt und zur Prüfung angezeigt.
            </p>
        </div>
    </div>

    <div style="height:14px"></div>

    <div id="eventsNotice" class="notice" style="display:none"></div>

    <section class="card" style="padding:26px;border-radius:22px">

        <!-- Step 1: file picker -->
        <div id="stepPick">
            <label class="file" style="display:block">
                <span style="font-weight:700;font-size:.95rem">Jahresprogramm PDF auswählen</span>
                <input type="file" id="eventsPdfInput" accept="application/pdf,.pdf"
                       style="margin-top:8px;width:100%">
            </label>
            <div id="parseSpinner" style="display:none;margin-top:14px;color:rgba(91,98,115,.9);font-size:.9rem">
                ⏳ PDF wird analysiert…
            </div>
        </div>

        <!-- Step 2: preview + confirm (hidden until parsing done) -->
        <div id="stepPreview" style="display:none;margin-top:24px;border-top:1px solid rgba(15,23,42,.08);padding-top:20px">
            <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
                <p id="previewSummary" style="margin:0;font-size:.92rem;color:rgba(91,98,115,.92)"></p>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button id="btnReset" class="btn btn-ghost" type="button">Andere PDF wählen</button>
                    <button id="btnImport" class="btn btn-primary" type="button">Importieren</button>
                </div>
            </div>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:6px 10px;border-bottom:2px solid rgba(15,23,42,.1);color:rgba(91,98,115,.9);font-size:.78rem;text-transform:uppercase;letter-spacing:.06em">Datum</th>
                            <th style="text-align:left;padding:6px 10px;border-bottom:2px solid rgba(15,23,42,.1);color:rgba(91,98,115,.9);font-size:.78rem;text-transform:uppercase;letter-spacing:.06em">Titel</th>
                            <th style="text-align:left;padding:6px 10px;border-bottom:2px solid rgba(15,23,42,.1);color:rgba(91,98,115,.9);font-size:.78rem;text-transform:uppercase;letter-spacing:.06em">Ort</th>
                        </tr>
                    </thead>
                    <tbody id="previewBody"></tbody>
                </table>
            </div>
        </div>

    </section>
</main>

<!-- PDF.js (runs entirely in the browser – no Python needed) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<!-- Parser logic (same source as admin/events-parser.js, inlined to avoid path issues) -->
<script>
<?php echo file_get_contents(__DIR__ . '/events-parser.js'); ?>
</script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

async function parsePdf(file) {
    const buf = await file.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
    const numPages = pdf.numPages;

    const allLines = [];

    for (let p = 1; p <= numPages; p++) {
        const page    = await pdf.getPage(p);
        const content = await page.getTextContent();

        // Group text items into lines by y-coordinate (±2 pt tolerance)
        const lineMap = [];
        for (const item of content.items) {
            if (!item.str.trim()) continue;
            const x = Math.round(item.transform[4]);
            const y = Math.round(item.transform[5]);
            const existing = lineMap.find(l => Math.abs(l.y - y) <= 2);
            if (existing) {
                existing.items.push({ x, text: item.str });
            } else {
                lineMap.push({ y, items: [{ x, text: item.str }] });
            }
        }

        for (const l of lineMap) {
            l.items.sort((a, b) => a.x - b.x);
            const text = l.items.map(i => i.text).join(' ').trim();
            if (text) {
                // Give each line a global y so pages don't have overlapping coordinates.
                // Page 1 top > page 1 bottom > page 2 top > page 2 bottom.
                const globalY = (numPages - p) * 10000 + l.y;
                allLines.push({ y: globalY, x0: l.items[0].x, text });
            }
        }
    }

    allLines.sort((a, b) => b.y - a.y);
    return buildEvents(allLines);
}

// ── UI wiring ─────────────────────────────────────────────────────────────────
const input    = document.getElementById('eventsPdfInput');
const spinner  = document.getElementById('parseSpinner');
const stepPick = document.getElementById('stepPick');
const stepPrev = document.getElementById('stepPreview');
const notice   = document.getElementById('eventsNotice');
const summary  = document.getElementById('previewSummary');
const tbody    = document.getElementById('previewBody');
const btnReset = document.getElementById('btnReset');
const btnImp   = document.getElementById('btnImport');

let parsedEvents = [];

function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
}

function fmtDate(iso) {
    try {
        return new Date(iso).toLocaleDateString('de-CH',
            { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
    } catch { return iso; }
}

function showNotice(type, msg) {
    notice.className = 'notice ' + type;
    notice.textContent = msg;
    notice.style.display = msg ? 'block' : 'none';
}

function resetToStep1() {
    input.value       = '';
    parsedEvents      = [];
    stepPrev.style.display = 'none';
    stepPick.style.display = 'block';
    showNotice('', '');
}

// Parse as soon as a file is chosen
input.addEventListener('change', async () => {
    const file = input.files[0];
    if (!file) return;

    showNotice('', '');
    spinner.style.display = 'block';

    try {
        parsedEvents = await parsePdf(file);
    } catch (e) {
        spinner.style.display = 'none';
        showNotice('err', 'PDF konnte nicht gelesen werden: ' + e.message);
        return;
    }

    spinner.style.display = 'none';

    if (!parsedEvents.length) {
        showNotice('err', 'Keine Events im PDF gefunden. Ist es das richtige Jahresprogramm?');
        return;
    }

    // Render preview table
    tbody.innerHTML = parsedEvents.map(ev => `
        <tr>
            <td style="padding:9px 10px;border-bottom:1px solid rgba(15,23,42,.07);white-space:nowrap">${esc(fmtDate(ev.start))}</td>
            <td style="padding:9px 10px;border-bottom:1px solid rgba(15,23,42,.07)">${esc(ev.title)}</td>
            <td style="padding:9px 10px;border-bottom:1px solid rgba(15,23,42,.07);color:rgba(91,98,115,.9)">${esc(ev.location ?? '—')}</td>
        </tr>`).join('');

    summary.textContent = `${parsedEvents.length} Events gefunden – alles korrekt? Dann importieren.`;
    stepPrev.style.display = 'block';
});

btnReset.addEventListener('click', resetToStep1);

// Commit parsed events to server
btnImp.addEventListener('click', async () => {
    if (!parsedEvents.length) return;

    btnImp.disabled = true;
    btnImp.textContent = 'Wird gespeichert…';
    showNotice('', '');

    const fd = new FormData();
    fd.append('csrf',        <?= json_encode($_SESSION['csrf'] ?? '') ?>);
    fd.append('events_json', JSON.stringify(parsedEvents));

    try {
        const res  = await fetch('../api/parse-pdf-events.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.ok) {
            showNotice('err', data.message || 'Fehler beim Speichern');
        } else {
            showNotice('ok', data.message);
            summary.textContent =
                `${parsedEvents.length} Events im PDF · ${data.imported} neu importiert · ${data.skipped} bereits vorhanden`;
            btnImp.style.display = 'none';
        }
    } catch (e) {
        showNotice('err', 'Netzwerkfehler: ' + e.message);
    } finally {
        btnImp.disabled  = false;
        btnImp.textContent = 'Importieren';
    }
});
</script>

</body>
</html>