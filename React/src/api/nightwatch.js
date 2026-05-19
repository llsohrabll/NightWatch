import { authFetch, readJsonSafe } from './auth.js';

export async function getWriteups({ limit = 10, page, q } = {}) {
  const params = new URLSearchParams({ limit: String(limit) });
  if (page) params.set('page', String(page));
  if (q && String(q).trim()) params.set('q', String(q).trim());
  const response = await fetch(`/functions/get_writeups.php?${params.toString()}`, {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { Accept: 'application/json' },
  });
  const data = await readJsonSafe(response);
  if (!response.ok || !data || !data.success) {
    throw new Error(data?.error || 'Writeups could not be loaded.');
  }
  return data;
}

export async function getPanelData() {
  const response = await authFetch('/functions/panel.php', {
    headers: { Accept: 'application/json' },
  });
  const data = response ? await readJsonSafe(response) : null;
  if (!response || !response.ok || !data || !data.success) {
    const error = new Error(data?.error || 'Panel could not be loaded.');
    error.status = response?.status;
    throw error;
  }
  return data;
}

export async function getUserProfile(username) {
  const response = await fetch(`/functions/get_user_profile.php?username=${encodeURIComponent(username)}`, {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  });
  const data = await readJsonSafe(response);
  if (!response.ok || !data || !data.success) {
    throw new Error(data?.error || 'Profile not found.');
  }
  return data;
}
