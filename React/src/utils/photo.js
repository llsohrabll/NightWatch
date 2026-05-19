const DEFAULT_PHOTO = '/assets/nightwatch_symbol.png';

export function safePhotoUrl(photoPath) {
  const raw = String(photoPath || 'assets/nightwatch_symbol.png').trim();
  if (!raw) return DEFAULT_PHOTO;

  try {
    const absolute = new URL(raw);
    if (absolute.protocol === 'https:' && /\.(?:jpe?g|png)$/i.test(absolute.pathname)) {
      return absolute.href;
    }
    return DEFAULT_PHOTO;
  } catch {
    // Relative path; validate below.
  }

  let normalized = raw.replace(/\\/g, '/').replace(/^\/+/, '');
  try {
    normalized = decodeURIComponent(normalized).replace(/\\/g, '/').replace(/^\/+/, '');
  } catch {
    return DEFAULT_PHOTO;
  }
  if (normalized.includes('..') || normalized.includes('//')) return DEFAULT_PHOTO;
  if (normalized === 'assets/nightwatch_symbol.png') return DEFAULT_PHOTO;
  if (/^functions\/photo\.php\?file=[A-Za-z0-9._%-]+\.(?:jpe?g|png)$/i.test(normalized)) return `/${normalized}`;
  if (/^uploads\/photos\/[A-Za-z0-9._-]+\.(?:jpe?g|png)$/i.test(normalized)) return `/${normalized}`;
  return DEFAULT_PHOTO;
}

export function isDefaultPhoto(photoUrl) {
  return !photoUrl || photoUrl === DEFAULT_PHOTO;
}
