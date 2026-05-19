export function formatDate(value, options = { year: 'numeric', month: 'short', day: 'numeric' }) {
  const timestamp = Number(value);
  if (!Number.isFinite(timestamp)) return '';
  return new Intl.DateTimeFormat(undefined, options).format(new Date(timestamp));
}

export function formatDateTime(value) {
  const parsed = Date.parse(String(value || '').replace(' ', 'T'));
  return Number.isNaN(parsed)
    ? ''
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(parsed));
}

export function formatDuration(msLeft) {
  const totalSeconds = Math.max(0, Math.ceil(msLeft / 1000));
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  if (hours > 0) return `${hours}h ${minutes}m ${seconds}s`;
  if (minutes > 0) return `${minutes}m ${seconds}s`;
  return `${seconds}s`;
}

export function parseExpiry(value) {
  if (!value) return null;
  const normalized = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
  const parsed = Date.parse(normalized);
  return Number.isNaN(parsed) ? null : parsed;
}

export function estimateReadMinutes(content) {
  const words = String(content || '').trim().split(/\s+/).filter(Boolean).length;
  return Math.max(1, Math.ceil(words / 220));
}
