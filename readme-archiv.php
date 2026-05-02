<?php
declare(strict_types=1);

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Try ../../readme/archive first
$archiveFsDir = realpath(__DIR__ . '/../../readme/archive');
if (!$archiveFsDir || !is_dir($archiveFsDir)) {
    $archiveFsDir = realPath(__DIR__ . '/readme/archive');
}

if (!$archiveFsDir || !is_writable($archiveFsDir)) {
    $archiveFsDir = null;
}

$archiveUrlPrefix = '/readme/archive/';

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($docRoot && !is_dir(rtrim($docRoot, '/\\') . '/readme/archive')) {
    // fallback: try relative URL from current script directory
    $archiveUrlPrefix = 'readme/archive/';
}

$items = [];
if ($archiveFsDir && is_dir($archiveFsDir)) {
    $files = scandir($archiveFsDir) ?: [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;

        if (!preg_match('~^(readme(\d+)_([0-9]{2}))\.(pdf|jpg)$~i', $f, $m)) continue;

        $base = $m[1];        // readme54_25
        $num = (int)$m[2];   // 54
        $yy = (int)$m[3];   // 25
        $ext = strtolower($m[4]);

        $year = 2000 + $yy;

        if (!isset($items[$base])) {
            $items[$base] = [
                'base' => $base,
                'num' => $num,
                'year' => $year,
                'pdf' => null,
                'jpg' => null,
                'mtime' => 0,
            ];
        }

        $path = $archiveFsDir . DIRECTORY_SEPARATOR . $f;
        $items[$base]['mtime'] = max($items[$base]['mtime'], (int)@filemtime($path));
        if ($ext === 'pdf') $items[$base]['pdf'] = $archiveUrlPrefix . $f;
        if ($ext === 'jpg') $items[$base]['jpg'] = $archiveUrlPrefix . $f;
    }
}

// keep only entries that have a PDF (JPG is optional but recommended)
$items = array_values(array_filter($items, fn($x) => !empty($x['pdf'])));

// Sort: year desc, num desc, then mtime desc
usort($items, function ($a, $b) {
    if ($a['year'] !== $b['year']) return $b['year'] <=> $a['year'];
    if ($a['num'] !== $b['num']) return $b['num'] <=> $a['num'];
    return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
});

// Group by year
$byYear = [];
foreach ($items as $it) {
    $byYear[(string)$it['year']][] = $it;
}
krsort($byYear); // year desc
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Readme-Archiv · UZH Alumni Informatik</title>
    <meta name="description" content="Archiv vergangener Readme-Ausgaben (UZH Alumni Informatik)."/>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="styles.css?v=20251117"/>
</head>
<body>

<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="index.html#top" aria-label="Startseite">
            <img src="uploads/logos/alumni_logo_header.png" alt="UZH Alumni Informatik">
        </a>

        <a class="brand-uzh" href="https://www.uzh.ch" target="_blank" rel="noopener" aria-label="UZH Website">
            <img src="uploads/logos/uzh-logo.svg" alt="Universität Zürich">
        </a>

        <nav class="nav">
            <a href="index.html#readme">Readme</a>
            <a href="index.html#contact" class="btn btn-primary">Kontakt</a>
        </nav>

        <button class="nav-toggle" aria-label="Menü öffnen" aria-expanded="false">☰</button>
    </div>
</header>

<main class="section">
    <div class="container">

        <div class="archive-head" style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px">
            <a class="back-inline" href="index.html#readme">Zurück</a>
            <div style="display:flex;align-items:baseline;gap:14px;flex-wrap:wrap">
                <h1 style="margin:0">Readme-Archiv</h1>
            </div>
            <p class="muted" style="margin:0">Frühere Ausgaben unseres Vereinsbulletins – nach Jahr gruppiert.</p>
        </div>

        <section class="card archive-card">
            <div class="archive-toolbar">
                <div class="muted" id="archiveCount">—</div>
                <div class="archive-search">
                    <input id="archiveQ" type="search" placeholder="Suche nach Ausgabe, Jahr …"
                           aria-label="Archiv-Suche">
                </div>
            </div>

            <?php if (empty($items)): ?>
                <p class="muted" style="margin:14px 0 0">Keine Ausgaben gefunden in <code
                            style="font-family:ui-monospace">../../readme/archive</code>.</p>
            <?php else: ?>

                <div class="archive-list" id="archiveList">
                    <?php foreach ($byYear as $year => $list): ?>
                        <div class="archive-year" data-year="<?= h($year) ?>">
                            <div class="archive-year-row">
                                <div class="archive-year-title"><?= h($year) ?></div>
                            </div>

                            <div class="archive-year-items">
                                <?php foreach ($list as $it):
                                    $title = 'readme ' . $it['num'];
                                    $subtitle = 'Ausgabe ' . $it['num'] . ' · ' . $it['year'];
                                    $jpg = $it['jpg'] ?: 'uploads/logos/uzh-placeholder.jpg';
                                    $pdf = $it['pdf'];
                                    ?>
                                    <div class="archive-item-row"
                                         data-search="<?= h(strtolower($title . ' ' . $subtitle . ' ' . $it['base'])) ?>">
                                        <a class="cover-thumb" href="<?= h($pdf) ?>" target="_blank" rel="noopener"
                                           aria-label="PDF öffnen: <?= h($title) ?>">
                                            <img src="<?= h($jpg) ?>" alt="Cover <?= h($title) ?>" loading="lazy">
                                        </a>

                                        <div class="archive-item-meta">
                                            <div class="issue-title"><?= h($title) ?></div>
                                            <div class="issue-sub muted"><?= h($subtitle) ?></div>
                                        </div>

<!--                                        <div class="archive-item-actions">-->
<!--                                            <a class="pdf-link" href="--><?php //= h($pdf) ?><!--" target="_blank" rel="noopener">-->
<!--                                                <span class="pdf-ic" aria-hidden="true">-->
<!--                                                    <svg viewBox="0 0 24 24" fill="none"-->
<!--                                                         xmlns="http://www.w3.org/2000/svg">-->
<!--                                                        <path d="M7 3h7l4 4v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"-->
<!--                                                              stroke="currentColor" stroke-width="2"-->
<!--                                                              stroke-linejoin="round"/>-->
<!--                                                        <path d="M14 3v5h5" stroke="currentColor" stroke-width="2"-->
<!--                                                              stroke-linejoin="round"/>-->
<!--                                                        <path d="M8 17h8M8 13h8M8 9h4" stroke="currentColor"-->
<!--                                                              stroke-width="2" stroke-linecap="round"/>-->
<!--                                                    </svg>-->
<!--                                                </span>-->
<!--                                                PDF-->
<!--                                            </a>-->
<!--                                        </div>-->
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </section>

    </div>
</main>

<footer class="footer">
    <div class="container footer-inner">
        <p>© <span id="y"></span> UZH Alumni Informatik · Zürich</p>
        <div class="footer-right">
            <nav class="footer-nav" aria-label="Footer Navigation">
                <a href="index.html#top">Top</a>
                <a href="#" onclick="alert('Impressum folgt');return false;">Impressum</a>
                <a href="#" onclick="alert('Nutzungsbedingungen folgen');return false;">Nutzungs­bedingungen</a>
            </nav>
            <div class="social-links" aria-label="Social Media">
                <a class="social" href="#" target="_blank" rel="noopener" aria-label="Instagram">
                    <span class="social-ic" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path
                                    d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Z"
                                    stroke="currentColor" stroke-width="2"/><path
                                    d="M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" stroke-width="2"/><path
                                    d="M17.5 6.5h.01" stroke="currentColor" stroke-width="3"
                                    stroke-linecap="round"/></svg>
                    </span>
                </a>
                <a class="social" href="#" target="_blank" rel="noopener" aria-label="LinkedIn">
                    <span class="social-ic" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 4h4v16H4V4Z"
                                                                                                      stroke="currentColor"
                                                                                                      stroke-width="2"/><path
                                    d="M8 9h4v2.2c.6-1.3 2-2.3 3.9-2.3 3 0 4.1 1.9 4.1 5.3V20h-4v-5.2c0-1.5-.3-2.6-1.6-2.6-1.3 0-2.4 1-2.4 2.9V20H8V9Z"
                                    stroke="currentColor" stroke-width="2"/><path d="M6 6.5h.01" stroke="currentColor"
                                                                                  stroke-width="3"
                                                                                  stroke-linecap="round"/></svg>
                    </span>
                </a>
            </div>
        </div>
    </div>
</footer>

<script>
    // Mobile nav
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.querySelector('.nav');
    if (toggle && nav) {
        toggle.addEventListener('click', () => {
            const open = nav.classList.toggle('open');
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
        });
        nav.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                nav.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'Menü öffnen');
            });
        });
    }

    // Footer year
    document.getElementById('y').textContent = new Date().getFullYear();

    // Search
    const q = document.getElementById('archiveQ');
    const countEl = document.getElementById('archiveCount');
    const rows = [...document.querySelectorAll('.archive-item-row')];
    const yearBlocks = [...document.querySelectorAll('.archive-year')];

    function norm(s) {
        return String(s || '').toLowerCase().trim();
    }

    function applyFilter() {
        const term = norm(q.value);
        let visible = 0;

        rows.forEach(r => {
            const hay = r.getAttribute('data-search') || norm(r.innerText);
            const match = !term || hay.includes(term);
            r.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        // hide year blocks with zero visible items
        yearBlocks.forEach(b => {
            const any = [...b.querySelectorAll('.archive-item-row')].some(r => r.style.display !== 'none');
            b.style.display = any ? '' : 'none';
        });

        countEl.textContent = visible + ' Eintrag' + (visible === 1 ? '' : 'e');
    }

    q.addEventListener('input', () => {
        window.clearTimeout(window.__archT);
        window.__archT = window.setTimeout(applyFilter, 120);
    });

    applyFilter();
</script>

</body>
</html>