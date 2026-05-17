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

  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const ctx = canvas.getContext('2d');
  let width = 0;
  let height = 0;
  let stars = [];
  let meteors = [];

  function random(min, max) {
    return Math.random() * (max - min) + min;
  }

  class Star {
    constructor() {
      this.reset(true);
    }

    reset(anywhere = false) {
      this.x = anywhere ? random(0, width) : random(-40, width + 40);
      this.y = anywhere ? random(0, height) : random(-40, height + 40);
      this.radius = random(0.55, 1.9);
      this.vx = random(-0.10, 0.10);
      this.vy = random(-0.06, 0.12);
      this.alpha = random(0.25, 0.95);
      this.phase = random(0, Math.PI * 2);
      this.twinkle = random(0.006, 0.018);
      this.hue = Math.random() > 0.78 ? '196, 155, 255' : '125, 231, 255';
    }

    update() {
      this.phase += this.twinkle;
      this.alpha = 0.35 + Math.abs(Math.sin(this.phase)) * 0.55;

      if (!reduceMotion) {
        this.x += this.vx;
        this.y += this.vy;
      }

      if (this.x < -60) this.x = width + 60;
      if (this.x > width + 60) this.x = -60;
      if (this.y < -60) this.y = height + 60;
      if (this.y > height + 60) this.y = -60;
    }

    draw() {
      ctx.beginPath();
      ctx.fillStyle = `rgba(${this.hue}, ${this.alpha})`;
      ctx.shadowBlur = this.radius > 1.4 ? 9 : 0;
      ctx.shadowColor = `rgba(${this.hue}, 0.9)`;
      ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
      ctx.fill();
      ctx.shadowBlur = 0;
    }
  }

  class Meteor {
    constructor() {
      this.reset();
    }

    reset() {
      this.x = random(width * 0.35, width + 240);
      this.y = random(-height * 0.35, height * 0.45);
      this.length = random(90, 190);
      this.speed = random(8, 16);
      this.wait = random(120, 560);
      this.active = false;
      this.alpha = random(0.42, 0.82);
    }

    update() {
      if (reduceMotion) return;
      if (!this.active) {
        this.wait -= 1;
        if (this.wait <= 0) this.active = true;
        return;
      }

      this.x -= this.speed;
      this.y += this.speed * 0.58;
      if (this.x < -this.length || this.y > height + this.length) this.reset();
    }

    draw() {
      if (!this.active || reduceMotion) return;
      const tailX = this.x + this.length;
      const tailY = this.y - this.length * 0.58;
      const gradient = ctx.createLinearGradient(this.x, this.y, tailX, tailY);
      gradient.addColorStop(0, `rgba(255, 255, 255, ${this.alpha})`);
      gradient.addColorStop(0.18, `rgba(125, 231, 255, ${this.alpha * 0.35})`);
      gradient.addColorStop(1, 'rgba(125, 231, 255, 0)');
      ctx.strokeStyle = gradient;
      ctx.lineWidth = 1.4;
      ctx.lineCap = 'round';
      ctx.beginPath();
      ctx.moveTo(this.x, this.y);
      ctx.lineTo(tailX, tailY);
      ctx.stroke();
    }
  }

  function resize() {
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    width = window.innerWidth;
    height = window.innerHeight;

    canvas.width = Math.floor(width * ratio);
    canvas.height = Math.floor(height * ratio);
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

    const count = Math.min(185, Math.max(70, Math.floor((width * height) / 13000)));
    stars = Array.from({ length: count }, () => new Star());
    meteors = Array.from({ length: width > 680 ? 3 : 1 }, () => new Meteor());
  }

  function drawConstellations() {
    const maxDistance = Math.min(154, Math.max(92, width / 9));
    for (let i = 0; i < stars.length; i += 1) {
      for (let j = i + 1; j < stars.length; j += 1) {
        const dx = stars[i].x - stars[j].x;
        const dy = stars[i].y - stars[j].y;
        const dist = Math.hypot(dx, dy);
        if (dist < maxDistance) {
          const opacity = (1 - dist / maxDistance) * 0.16;
          ctx.strokeStyle = `rgba(125, 231, 255, ${opacity})`;
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.moveTo(stars[i].x, stars[i].y);
          ctx.lineTo(stars[j].x, stars[j].y);
          ctx.stroke();
        }
      }
    }
  }

  function draw() {
    ctx.clearRect(0, 0, width, height);
    stars.forEach((star) => { star.update(); star.draw(); });
    drawConstellations();
    meteors.forEach((meteor) => { meteor.update(); meteor.draw(); });
    requestAnimationFrame(draw);
  }

  window.addEventListener('resize', resize, { passive: true });
  resize();
  draw();
})();
