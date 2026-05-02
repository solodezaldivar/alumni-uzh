<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$EVENTS_FILE = realpath(__DIR__ . '/../events.json') ?: (__DIR__ . '/../events.json');
$UPLOADS_DIR = __DIR__ . '/../uploads';

$events = [];
if (file_exists($EVENTS_FILE)) {
    $raw = json_decode(file_get_contents($EVENTS_FILE), true);
    if (is_array($raw)) {
        $events = $raw;
    }
}

// Sort upcoming first
usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));
$now = (new DateTime())->format(DateTime::ATOM);

// Scan for PDFs in uploads/events/
$pdfFiles = [];
$eventsUploadDir = $UPLOADS_DIR . '/events';
if (is_dir($eventsUploadDir)) {
    foreach (glob($eventsUploadDir . '/*.pdf') as $file) {
        $pdfFiles[] = 'events/' . basename($file);
    }
}
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Admin · Events · UZH Alumni Informatik</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles.css?v=20251117"/>

    <style>
        .admin-shell   { max-width: 980px; margin: 0 auto; padding: 40px 20px 80px; }
        .panel         { background: var(--card); border: 1px solid var(--line); border-radius: 20px;
                         padding: 28px; box-shadow: var(--shadow-sm); margin-bottom: 28px; }
        .panel h3      { margin: 0 0 18px; font-size: 1.05rem; }
        .notice        { padding: 12px 16px; border-radius: 12px; border: 1px solid transparent;
                         font-size: .92rem; display: none; margin-bottom: 16px; }
        .notice.ok     { background: rgba(5,150,105,.07); border-color: rgba(5,150,105,.3); color: #065f46; display: block; }
        .notice.err    { background: rgba(220,38,38,.07);  border-color: rgba(220,38,38,.3);  color: #991b1b; display: block; }
        .notice.info   { background: rgba(85,105,149,.07); border-color: rgba(85,105,149,.25); color: #334155; display: block; }

        /* PDF import form */
        .import-row    { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
        .import-row select, .import-row input[type=text]
                       { flex: 1; min-width: 240px; }
        .or-divider    { color: var(--muted); font-size: .85rem; padding: 0 4px; align-self: center; }

        /* Preview table */
        .preview-wrap  { display: none; }
        .preview-wrap.visible { display: block; }
        .events-tbl    { width: 100%; border-collapse: collapse; font-size: .88rem; }
        .events-tbl th { text-align: left; padding: 8px 10px; font-weight: 700;
                         border-bottom: 2px solid var(--line); color: var(--muted); font-size: .8rem;
                         text-transform: uppercase; letter-spacing: .05em; }
        .events-tbl td { padding: 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .events-tbl tr:last-child td { border-bottom: none; }
        .ev-past td    { opacity: .45; }
        .tag           { display: inline-block; padding: 2px 8px; border-radius: 999px;
                         background: rgba(85,105,149,.1); font-size: .78rem; color: var(--brand); }
        .badge-new     { background: rgba(5,150,105,.12); color: #065f46; }
        .date-cell     { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .del-btn       { padding: 4px 10px; font-size: .8rem; border-radius: 8px; cursor: pointer;
                         background: rgba(220,38,38,.08); border: 1px solid rgba(220,38,38,.25);
                         color: #b91c1c; transition: background .15s; }
        .del-btn:hover { background: rgba(220,38,38,.16); }
        .spinner       { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.4);
                         border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body class="theme-quartz-ivory">

<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="../index.html#top">
            <img src="../uploads/logos/alumni_logo_header.png" alt="UZH Alumni Informatik">
        </a>
        <nav class="nav" style="margin-left:auto">
            <a href="index.php">Readme Upload</a>
            <a href="../index.html#sponsors">Website</a>
            <a href="events.php" class="btn btn-primary" aria-current="page">Events</a>
        </nav>
    </div>
</header>

<main class="admin-shell">
    <h2 style="margin:0 0 6px">Admin · Events</h2>
    <p class="muted" style="margin:0 0 28px">PDF-Import und Verwaltung der Event-Liste.</p>

    <!-- ── PDF Import ──────────────────────────────────────────────────────── -->
    <div class="panel">
        <h3>Events aus PDF importieren</h3>

        <div id="importNotice" class="notice"></div>

        <form id="importForm" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

            <div class="import-row">
                <?php if ($pdfFiles): ?>
                <select name="pdf_path" id="pdfSelect">
                    <?php foreach ($pdfFiles as $f): ?>
                        <option value="<?= h($f) ?>"><?= h(basename($f)) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="or-divider">oder Pfad:</span>
                <?php endif; ?>
                <input type="text" name="pdf_path_manual" id="pdfManual"
                       placeholder="events/naechste_events.pdf"
                       style="display:<?= $pdfFiles ? 'none' : 'block' ?>">
                <button class="btn btn-primary" type="submit" id="importBtn">
                    PDF parsen &amp; importieren
                </button>
            </div>
            <?php if ($pdfFiles): ?>
            <p class="muted" style="font-size:.82rem;margin:10px 0 0">
                <a href="#" id="toggleManual" style="color:var(--brand)">Anderen Pfad eingeben</a>
            </p>
            <?php endif; ?>
        </form>

        <div class="preview-wrap" id="previewWrap" style="margin-top:24px">
            <h4 style="margin:0 0 12px;font-size:.95rem" id="previewTitle"></h4>
            <div style="overflow-x:auto">
                <table class="events-tbl" id="previewTbl">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Titel</th>
                            <th>Ort</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="previewBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Current events ──────────────────────────────────────────────────── -->
    <div class="panel">
        <h3>Aktuelle Event-Liste
            <span class="tag" style="font-size:.78rem;margin-left:8px"><?= count($events) ?> Events</span>
        </h3>
        <div id="deleteNotice" class="notice"></div>
        <div style="overflow-x:auto">
            <table class="events-tbl" id="allEventsTbl">
                <thead>
                    <tr><th>Datum</th><th>Titel</th><th>Ort</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($events as $ev):
                    $isPast = ($ev['start'] < $now);
                    $dateStr = date('d.m.Y H:i', strtotime($ev['start']));
                ?>
                    <tr class="<?= $isPast ? 'ev-past' : '' ?>" id="row-<?= h($ev['id']) ?>">
                        <td class="date-cell"><?= h($dateStr) ?></td>
                        <td><?= h($ev['title']) ?></td>
                        <td><?= h($ev['location'] ?? '') ?></td>
                        <td>
                            <button class="del-btn" data-id="<?= h($ev['id']) ?>"
                                    data-csrf="<?= h($_SESSION['csrf']) ?>">
                                Löschen
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
// ── Helpers ──────────────────────────────────────────────────────────────────
const fmtDate = iso => {
    const d = new Date(iso);
    return d.toLocaleDateString('de-CH', {day:'2-digit', month:'2-digit', year:'numeric'})
           + ' ' + d.toLocaleTimeString('de-CH', {hour:'2-digit', minute:'2-digit'});
};

const showNotice = (el, type, msg) => {
    el.className = 'notice ' + type;
    el.textContent = msg;
    el.scrollIntoView({behavior:'smooth', block:'nearest'});
};

// ── Toggle manual path input ─────────────────────────────────────────────────
const toggleLink  = document.getElementById('toggleManual');
const pdfSelect   = document.getElementById('pdfSelect');
const pdfManual   = document.getElementById('pdfManual');

if (toggleLink) {
    toggleLink.addEventListener('click', e => {
        e.preventDefault();
        const show = pdfManual.style.display === 'none';
        pdfManual.style.display  = show ? 'block' : 'none';
        if (pdfSelect) pdfSelect.style.display = show ? 'none' : 'block';
        toggleLink.textContent = show ? 'Aus Liste wählen' : 'Anderen Pfad eingeben';
    });
}

// ── PDF Import ───────────────────────────────────────────────────────────────
document.getElementById('importForm').addEventListener('submit', async e => {
    e.preventDefault();
    const notice = document.getElementById('importNotice');
    const btn    = document.getElementById('importBtn');
    const wrap   = document.getElementById('previewWrap');

    // Determine pdf_path: manual input wins if visible
    let pdfPath = '';
    if (pdfManual && pdfManual.style.display !== 'none') {
        pdfPath = pdfManual.value.trim() || 'events/naechste_events.pdf';
    } else if (pdfSelect) {
        pdfPath = pdfSelect.value;
    } else {
        pdfPath = (pdfManual ? pdfManual.value.trim() : '') || 'events/naechste_events.pdf';
    }

    const csrf = document.querySelector('#importForm [name=csrf]').value;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Parsen…';
    showNotice(notice, 'info', 'PDF wird analysiert…');
    wrap.classList.remove('visible');

    try {
        const fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('pdf_path', pdfPath);

        const res  = await fetch('../api/parse-pdf-events.php', {method:'POST', body:fd});
        const data = await res.json();

        if (!data.ok) {
            showNotice(notice, 'err', data.message || 'Fehler beim Import');
        } else {
            showNotice(notice, 'ok', data.message);

            // Render preview table
            const tbody = document.getElementById('previewBody');
            tbody.innerHTML = '';
            const title = document.getElementById('previewTitle');

            if (Array.isArray(data.events) && data.events.length > 0) {
                title.textContent = `${data.events.length} Events aus PDF`;
                data.events.forEach(ev => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="date-cell">${fmtDate(ev.start)}</td>
                        <td>${escHtml(ev.title)}</td>
                        <td>${escHtml(ev.location || '')}</td>
                        <td><span class="tag badge-new">importiert</span></td>`;
                    tbody.appendChild(tr);
                });
                wrap.classList.add('visible');
            }

            // Reload page to refresh event list after short delay
            if (data.imported > 0) {
                setTimeout(() => location.reload(), 1500);
            }
        }
    } catch (err) {
        showNotice(notice, 'err', 'Netzwerkfehler: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'PDF parsen &amp; importieren';
    }
});

// ── Delete event ─────────────────────────────────────────────────────────────
document.getElementById('allEventsTbl').addEventListener('click', async e => {
    const btn = e.target.closest('.del-btn');
    if (!btn) return;

    const id   = btn.dataset.id;
    const csrf = btn.dataset.csrf;
    if (!confirm('Event wirklich löschen?')) return;

    btn.disabled = true;
    btn.textContent = '…';

    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fd.append('csrf', csrf);

        const res  = await fetch('../api/add-event.php', {method:'POST', body:fd});
        const data = await res.json();

        if (data.ok || res.status === 200) {
            // Remove row from table
            const row = document.getElementById('row-' + id);
            if (row) row.remove();
            const notice = document.getElementById('deleteNotice');
            showNotice(notice, 'ok', 'Event gelöscht.');
        } else {
            btn.disabled = false;
            btn.textContent = 'Löschen';
            alert(data.error || 'Fehler beim Löschen');
        }
    } catch (err) {
        btn.disabled = false;
        btn.textContent = 'Löschen';
        alert('Netzwerkfehler: ' + err.message);
    }
});

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

</body>
</html>
