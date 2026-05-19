import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import StarCanvas from '../components/StarCanvas.jsx';
import useBodyClass from '../hooks/useBodyClass.js';
import { getUserProfile } from '../api/nightwatch.js';
import { formatDate } from '../utils/date.js';
import { safePhotoUrl } from '../utils/photo.js';

export default function UserProfilePage() {
  useBodyClass('public-screen');
  const [params] = useSearchParams();
  const username = params.get('username') || '';
  const [state, setState] = useState({ loading: true, error: '', profile: null, writeups: [] });

  useEffect(() => {
    let cancelled = false;
    async function load() {
      if (!/^[a-zA-Z0-9_]{3,50}$/.test(username)) {
        setState({ loading: false, error: 'Add ?username=member_name to the URL.', profile: null, writeups: [] });
        return;
      }
      try {
        const data = await getUserProfile(username);
        if (!cancelled) setState({ loading: false, error: '', profile: data.profile, writeups: data.writeups || [] });
      } catch (error) {
        if (!cancelled) setState({ loading: false, error: error.message || 'Profile not found.', profile: null, writeups: [] });
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [username]);

  return (
    <>
      <StarCanvas id="star-canvas" variant="public" />
      <main className="page-shell">
        <section className="hero-card glass-panel profile-shell">
          <Link to="/" className="brand-pill">
            <img src="/assets/nightwatch_symbol.png" alt="Night Watch" className="brand-icon-sm" />
            Night Watch
          </Link>

          {state.loading ? <p className="profile-status">Loading profile...</p> : null}
          {state.error ? <p className="profile-status">{state.error}</p> : null}

          {state.profile ? (
            <div className="profile-root">
              <img
                src={safePhotoUrl(state.profile.photo_path)}
                alt={`${state.profile.username} profile`}
                className="profile-photo-lg"
              />
              <h1>{state.profile.username}</h1>
              <p className="profile-meta">Member since {formatDate(state.profile.created_at_ms, { dateStyle: 'medium' })}</p>

              <div className="profile-writeups">
                {state.writeups.length ? state.writeups.map((writeup) => (
                  <article className="profile-writeup" key={writeup.id}>
                    <h3>{writeup.title}</h3>
                    <p>{writeup.excerpt || 'No excerpt available.'}</p>
                    <small>
                      {[writeup.category, formatDate(writeup.published_at_ms, { dateStyle: 'medium' })].filter(Boolean).join(' / ')}
                    </small>
                  </article>
                )) : <p>No published writeups yet.</p>}
              </div>
            </div>
          ) : null}
        </section>
      </main>
    </>
  );
}
