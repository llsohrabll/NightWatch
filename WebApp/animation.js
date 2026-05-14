(function () {
  'use strict';

  const canvas = document.getElementById('star-canvas');
  const searchInput = document.getElementById('writeup-search');
  const list = document.getElementById('writeup-list');
  const emptyState = document.getElementById('empty-state');
  const featured = document.getElementById('featured-writeup');
  const modal = document.getElementById('writeup-modal');
  const closeModalBtn = document.getElementById('close-writeup-modal');
  const modalTitle = document.getElementById('modal-writeup-title');
  const modalAuthor = document.getElementById('modal-writeup-author');
  const modalDate = document.getElementById('modal-writeup-date');
  const modalBody = document.getElementById('modal-writeup-body');

  let searchTimer = null;
  let activeWriteups = [];

  function formatDate(value) {
    const timestamp = Number(value);
    if (!Number.isFinite(timestamp)) return '';
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(timestamp));
  }

  function estimateReadMinutes(content) {
    const words = String(content || '').trim().split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.ceil(words / 220));
  }

  function clearNode(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function createWriteupCard(writeup) {
    const article = document.createElement('article');
    article.className = 'writeup-card';

    const tag = document.createElement('span');
    tag.className = 'tag';
    tag.textContent = writeup.author?.username || 'Member';

    const title = document.createElement('h3');
    title.textContent = writeup.title || 'Untitled writeup';

    const excerpt = document.createElement('p');
    excerpt.textContent = writeup.excerpt || 'Open this writeup to read the full note.';

    const meta = document.createElement('div');
    meta.className = 'writeup-meta';
    const date = formatDate(writeup.published_at_ms);
    meta.textContent = [date, `${estimateReadMinutes(writeup.content_md)} min read`].filter(Boolean).join(' • ');

    const button = document.createElement('button');
    button.className = 'read-writeup-btn';
    button.type = 'button';
    button.textContent = 'Read writeup';
    button.addEventListener('click', () => openWriteup(writeup));

    article.append(tag, title, excerpt, meta, button);
    return article;
  }

  function renderFeatured(writeup) {
    if (!featured) return;
    clearNode(featured);

    const tag = document.createElement('span');
    tag.className = 'tag';
    tag.textContent = 'Latest';

    const title = document.createElement('h2');
    title.textContent = writeup ? writeup.title : 'No writeups published yet';

    const excerpt = document.createElement('p');
    excerpt.textContent = writeup
      ? (writeup.excerpt || 'Open the feed below to read the full writeup.')
      : 'Once a registered member publishes a writeup from the panel, it will appear here.';

    const meta = document.createElement('div');
    meta.className = 'card-meta';

    const author = document.createElement('span');
    author.textContent = writeup?.author?.username || 'Night Watch';

    const date = document.createElement('span');
    date.textContent = writeup ? (formatDate(writeup.published_at_ms) || 'Published') : 'Waiting for first post';

    meta.append(author, date);
    featured.append(tag, title, excerpt, meta);

    if (writeup) {
      const button = document.createElement('button');
      button.className = 'featured-read-btn';
      button.type = 'button';
      button.textContent = 'Read latest writeup';
      button.addEventListener('click', () => openWriteup(writeup));
      featured.appendChild(button);
    }
  }

  function renderWriteups(writeups) {
    if (!list) return;
    activeWriteups = Array.isArray(writeups) ? writeups : [];
    clearNode(list);

    if (activeWriteups.length === 0) {
      if (emptyState) emptyState.classList.add('show');
      renderFeatured(null);
      return;
    }

    if (emptyState) emptyState.classList.remove('show');
    activeWriteups.forEach((writeup) => list.appendChild(createWriteupCard(writeup)));
    renderFeatured(activeWriteups[0]);
  }

  async function loadWriteups(query = '') {
    if (!list) return;

    const params = new URLSearchParams({ limit: '12' });
    if (query.trim()) params.set('q', query.trim());

    try {
      const response = await fetch(`/functions/get_writeups.php?${params.toString()}`, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });

      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.success) {
        throw new Error(data?.error || 'Writeups could not be loaded.');
      }

      renderWriteups(data.writeups || []);
    } catch (error) {
      clearNode(list);
      const article = document.createElement('article');
      article.className = 'writeup-card loading-card';
      const tag = document.createElement('span');
      tag.className = 'tag';
      tag.textContent = 'Offline';
      const title = document.createElement('h3');
      title.textContent = 'Writeups are not available';
      const text = document.createElement('p');
      text.textContent = 'Check the database connection and the writeups table migration.';
      article.append(tag, title, text);
      list.appendChild(article);
      if (emptyState) emptyState.classList.remove('show');
    }
  }

  function openWriteup(writeup) {
    if (!modal || !modalTitle || !modalAuthor || !modalDate || !modalBody) return;

    modalTitle.textContent = writeup.title || 'Untitled writeup';
    modalAuthor.textContent = `By ${writeup.author?.username || 'Night Watch member'}`;
    modalDate.textContent = formatDate(writeup.published_at_ms);
    modalBody.textContent = writeup.content_md || writeup.excerpt || '';
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    closeModalBtn?.focus();
  }

  function closeWriteup() {
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }

  closeModalBtn?.addEventListener('click', closeWriteup);
  modal?.addEventListener('click', (event) => {
    if (event.target === modal) closeWriteup();
  });
  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeWriteup();
  });

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => loadWriteups(searchInput.value), 260);
    });
  }

  loadWriteups();

  if (!canvas) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const ctx = canvas.getContext('2d');
  let width = 0;
  let height = 0;
  let stars = [];

  function random(min, max) {
    return Math.random() * (max - min) + min;
  }

  function resize() {
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    width = window.innerWidth;
    height = window.innerHeight;

    canvas.width = Math.floor(width * ratio);
    canvas.height = Math.floor(height * ratio);
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

    const count = Math.min(110, Math.max(45, Math.floor((width * height) / 18000)));
    stars = Array.from({ length: count }, () => ({
      x: random(0, width),
      y: random(0, height),
      size: random(0.6, 1.8),
      speed: random(0.04, 0.12),
      alpha: random(0.25, 0.8)
    }));
  }

  function draw() {
    ctx.clearRect(0, 0, width, height);

    stars.forEach((star) => {
      if (!reduceMotion) {
        star.y += star.speed;
        if (star.y > height + 4) star.y = -4;
      }

      ctx.beginPath();
      ctx.fillStyle = `rgba(255, 255, 255, ${star.alpha})`;
      ctx.arc(star.x, star.y, star.size, 0, Math.PI * 2);
      ctx.fill();
    });

    requestAnimationFrame(draw);
  }

  window.addEventListener('resize', resize, { passive: true });
  resize();
  draw();
})();
