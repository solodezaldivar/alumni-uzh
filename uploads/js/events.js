(async function () {
    const container = document.getElementById('events');
    try {
        const res = await fetch('/events.json', { cache: 'no-cache' });
        const events = await res.json();

        // Split future vs past (optional)
        const now = new Date();
        const upcoming = events.filter(e => new Date(e.start) >= now);

        if (upcoming.length === 0) {
            container.innerHTML = `<p>No upcoming events yet. Check back soon!</p>`;
            return;
        }

        // Render
        container.innerHTML = upcoming.map(e => {
            const start = new Date(e.start);
            const end = e.end ? new Date(e.end) : null;

            const formatter = new Intl.DateTimeFormat(undefined, {
                weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });

            const when = end
                ? `${formatter.format(start)} – ${formatter.format(end)}`
                : `${formatter.format(start)}`;

            const img = e.image ? `<img loading="lazy" src="${e.image}" alt="">` : '';
            const url = e.url ? `<p><a href="${e.url}" target="_blank" rel="noopener">More info / signup</a></p>` : '';
            const tags = (e.tags ?? []).map(t => `<span class="tag">${t}</span>`).join('');

            return `
        <article class="event">
          ${img}
          <h2>${escapeHtml(e.title)}</h2>
          <div class="when">${when}</div>
          ${e.location ? `<div class="where">${escapeHtml(e.location)}</div>` : ''}
          ${e.description ? `<p>${escapeHtml(e.description)}</p>` : ''}
          ${url}
          ${tags ? `<div class="tags">${tags}</div>` : ''}
        </article>
      `;
        }).join('');
    } catch (err) {
        container.innerHTML = `<p>Failed to load events.</p>`;
        console.error(err);
    }

    function escapeHtml(s) {
        return String(s)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
})();
