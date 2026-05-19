import { useEffect, useState } from 'react';
import PublicHeader from '../components/PublicHeader.jsx';
import StarCanvas from '../components/StarCanvas.jsx';
import useBodyClass from '../hooks/useBodyClass.js';
import { authFetch, clearAuth, getSession, readJsonSafe } from '../api/auth.js';
import { formatDateTime } from '../utils/date.js';

const FILTERS = ['pending', 'published', 'rejected', 'draft'];

export default function AdminPage() {
  useBodyClass('public-screen');
  const [session, setSession] = useState(null);
  const [status, setStatus] = useState('pending');
  const [writeups, setWriteups] = useState([]);
  const [message, setMessage] = useState({ text: 'Checking access.', type: '' });
  const [loading, setLoading] = useState(false);
  const [reasons, setReasons] = useState({});
  const [accessChecked, setAccessChecked] = useState(false);

  useEffect(() => {
    let cancelled = false;
    async function init() {
      const activeSession = await getSession(true);
      if (!activeSession) {
        window.location.replace('/login');
        return;
      }
      if (cancelled) return;
      setSession(activeSession);
      setAccessChecked(true);
      if (!activeSession.is_admin) {
        setWriteups([]);
        setMessage({ type: 'error', text: 'Your account is signed in but is not an administrator.' });
      }
    }
    init();
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (session?.is_admin) loadQueue(status);
  }, [session, status]);

  async function loadQueue(nextStatus) {
    setLoading(true);
    setMessage({ text: 'Loading moderation queue.', type: '' });
    try {
      const response = await authFetch(`/functions/admin_writeups.php?status=${encodeURIComponent(nextStatus)}&limit=50`, {
        headers: { Accept: 'application/json' },
      });
      if (!response) throw new Error('Network error, please try again.');
      if (response.status === 401) {
        await clearAuth('/login');
        return;
      }
      const data = await readJsonSafe(response);
      if (!response.ok || !data || !data.success) {
        throw new Error(data?.error || 'Could not load moderation queue.');
      }
      setWriteups(data.writeups || []);
      setReasons({});
      setMessage({
        type: 'success',
        text: `${data.total || 0} ${nextStatus} writeup${Number(data.total) === 1 ? '' : 's'} loaded.`,
      });
    } catch (error) {
      setWriteups([]);
      setMessage({ type: 'error', text: error.message || 'Admin dashboard failed to load.' });
    } finally {
      setLoading(false);
    }
  }

  async function moderateWriteup(writeupId, action) {
    if (loading) return;
    setLoading(true);
    setMessage({ text: `${action === 'publish' ? 'Publishing' : 'Rejecting'} writeup #${writeupId}.`, type: '' });

    try {
      const formData = new FormData();
      formData.append('writeup_id', String(writeupId));
      formData.append('action', action);
      formData.append('reason', String(reasons[writeupId] || '').trim());

      const response = await authFetch('/functions/moderate_writeup.php', {
        method: 'POST',
        body: formData,
        headers: { Accept: 'application/json' },
      });
      if (!response) throw new Error('Network error, please try again.');
      if (response.status === 401) {
        await clearAuth('/login');
        return;
      }
      const data = await readJsonSafe(response);
      if (!response.ok || !data || !data.success) {
        throw new Error(data?.error || 'Moderation action failed.');
      }
      setMessage({ type: 'success', text: data.message || 'Moderation action saved.' });
      await loadQueue(status);
    } catch (error) {
      setMessage({ type: 'error', text: error.message || 'Moderation action failed.' });
    } finally {
      setLoading(false);
    }
  }

  function renderEmpty() {
    if (!accessChecked) return <p className="admin-muted">Checking access...</p>;
    if (!session?.is_admin) return <p className="admin-muted">Administrator access is required.</p>;
    return <p className="admin-muted">No {status} writeups found.</p>;
  }

  return (
    <>
      <StarCanvas id="star-canvas" variant="public" />
      <PublicHeader admin onLogout={() => clearAuth('/login')} />

      <main className="admin-shell">
        <section className="hero-card admin-card">
          <div className="admin-title-row">
            <div>
              <p className="kicker">Moderation queue</p>
              <h1>Review writeups before they go public.</h1>
              <p className="admin-muted">Admin endpoints still enforce server-side authorization. This page is only a safer UI for those endpoints.</p>
            </div>
            <span className="tag">{session?.is_admin ? `Admin: ${session.username || 'member'}` : 'Checking access'}</span>
          </div>

          <div className="admin-toolbar" aria-label="Moderation filters">
            {FILTERS.map((filter) => (
              <button
                key={filter}
                type="button"
                className={`filter-button${status === filter ? ' active' : ''}`}
                disabled={loading || !session?.is_admin}
                onClick={() => setStatus(filter)}
              >
                {filter[0].toUpperCase() + filter.slice(1)}
              </button>
            ))}
          </div>

          <p className={`admin-message${message.type ? ` ${message.type}` : ''}`} role="status" aria-live="polite">
            {message.text}
          </p>

          <div className="moderation-list" aria-live="polite">
            {writeups.length === 0 ? renderEmpty() : writeups.map((writeup) => {
              const tags = [writeup.category, Array.isArray(writeup.tags) ? writeup.tags.join(', ') : ''].filter(Boolean).join(' / ');
              return (
                <article className="moderation-card" key={writeup.id}>
                  <h2>{writeup.title || 'Untitled writeup'}</h2>
                  <p className="moderation-meta">
                    {[
                      `#${writeup.id}`,
                      writeup.author?.username ? `by ${writeup.author.username}` : 'unknown author',
                      writeup.status || status,
                      formatDateTime(writeup.created_at),
                    ].filter(Boolean).join(' / ')}
                  </p>
                  <p className="moderation-excerpt">{writeup.excerpt || 'No excerpt available.'}</p>
                  {tags ? <p className="moderation-tags">{tags}</p> : null}

                  {status === 'pending' ? (
                    <div className="moderation-actions">
                      <button type="button" className="moderation-button publish" disabled={loading} onClick={() => moderateWriteup(writeup.id, 'publish')}>
                        Publish
                      </button>
                      <button type="button" className="moderation-button reject" disabled={loading} onClick={() => moderateWriteup(writeup.id, 'reject')}>
                        Reject
                      </button>
                      <textarea
                        className="moderation-reason"
                        maxLength={500}
                        placeholder="Optional moderation reason, required by your process for rejections."
                        value={reasons[writeup.id] || ''}
                        onChange={(event) => setReasons((current) => ({ ...current, [writeup.id]: event.target.value }))}
                      />
                    </div>
                  ) : null}
                </article>
              );
            })}
          </div>
        </section>
      </main>
    </>
  );
}
