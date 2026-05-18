function safePhotoUrl(photoPath) {
  const raw = String(photoPath || 'assets/nightwatch_symbol.png').trim();
  if (!raw) return '/assets/nightwatch_symbol.png';
  try {
    const absolute = new URL(raw);
    if (absolute.protocol === 'https:' && /\.(?:jpe?g|png)$/i.test(absolute.pathname)) return absolute.href;
    return '/assets/nightwatch_symbol.png';
  } catch (error) {
    // Relative path; validate below.
  }
  const normalized = raw.replace(/\\/g, '/').replace(/^\/+/, '');
  if (normalized.includes('..') || normalized.includes('//')) return '/assets/nightwatch_symbol.png';
  if (normalized === 'assets/nightwatch_symbol.png') return '/assets/nightwatch_symbol.png';
  if (/^functions\/photo\.php\?file=[A-Za-z0-9._%-]+\.(?:jpe?g|png)$/i.test(normalized)) return `/${normalized}`;
  return '/assets/nightwatch_symbol.png';
}
function formatDate(ms) {
  const value = Number(ms);
  return Number.isFinite(value) ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value)) : '';
}
async function loadProfile() {
  const root = document.getElementById('profile-root');
  const username = new URLSearchParams(location.search).get('username') || '';
  if (!/^[a-zA-Z0-9_]{3,50}$/.test(username)) {
    root.textContent = 'Add ?username=member_name to the URL.';
    return;
  }
  const response = await fetch(`/functions/get_user_profile.php?username=${encodeURIComponent(username)}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || !data.success) {
    root.textContent = data?.error || 'Profile not found.';
    return;
  }
  root.textContent = '';
  const img = document.createElement('img');
  img.src = safePhotoUrl(data.profile.photo_path);
  img.alt = `${data.profile.username} profile photo`;
  img.className = 'profile-photo-lg';
  const title = document.createElement('h1'); title.textContent = data.profile.username;
  const meta = document.createElement('p'); meta.textContent = `Member since ${formatDate(data.profile.created_at_ms)}`;
  const list = document.createElement('div');
  (data.writeups || []).forEach((writeup) => {
    const article = document.createElement('article');
    const h = document.createElement('h3'); h.textContent = writeup.title;
    const p = document.createElement('p'); p.textContent = writeup.excerpt || 'No excerpt available.';
    const small = document.createElement('small'); small.textContent = [writeup.category, formatDate(writeup.published_at_ms)].filter(Boolean).join(' • ');
    article.append(h, p, small);
    list.appendChild(article);
  });
  if (!list.children.length) list.textContent = 'No published writeups yet.';
  root.append(img, title, meta, list);
}
loadProfile();
