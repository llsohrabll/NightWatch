import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import PublicHeader from '../components/PublicHeader.jsx';
import StarCanvas from '../components/StarCanvas.jsx';
import WriteupModal from '../components/WriteupModal.jsx';
import useBodyClass from '../hooks/useBodyClass.js';
import { getWriteups } from '../api/nightwatch.js';
import { estimateReadMinutes, formatDate } from '../utils/date.js';

function WriteupCard({ writeup, onRead }) {
  const date = formatDate(writeup.published_at_ms);
  const meta = [date, `${estimateReadMinutes(writeup.content_md)} min read`].filter(Boolean).join(' / ');

  return (
    <article className="writeup-card">
      <span className="tag">{writeup.author?.username || 'Member'}</span>
      <h3>{writeup.title || 'Untitled writeup'}</h3>
      <p>{writeup.excerpt || 'Open this writeup to read the full note.'}</p>
      <div className="writeup-meta">{meta}</div>
      <button className="read-writeup-btn" type="button" onClick={() => onRead(writeup)}>Read writeup</button>
    </article>
  );
}

function FeaturedWriteup({ writeup, onRead }) {
  return (
    <article className="hero-card featured-writeup-card">
      <span className="tag">{writeup ? 'Latest' : 'Waiting'}</span>
      <h2>{writeup ? writeup.title : 'No writeups published yet'}</h2>
      <p>
        {writeup
          ? (writeup.excerpt || 'Open the feed below to read the full writeup.')
          : 'Once a registered member publishes a writeup from the panel, it will appear here.'}
      </p>
      <div className="card-meta">
        <span>{writeup?.author?.username || 'Night Watch'}</span>
        <span>{writeup ? (formatDate(writeup.published_at_ms) || 'Published') : 'Waiting for first post'}</span>
      </div>
      {writeup ? (
        <button className="featured-read-btn" type="button" onClick={() => onRead(writeup)}>Read latest writeup</button>
      ) : null}
    </article>
  );
}

export default function HomePage() {
  useBodyClass('public-screen');
  const [query, setQuery] = useState('');
  const [writeups, setWriteups] = useState([]);
  const [status, setStatus] = useState('loading');
  const [activeWriteup, setActiveWriteup] = useState(null);
  const featured = useMemo(() => writeups[0] || null, [writeups]);

  useEffect(() => {
    let cancelled = false;
    const timer = window.setTimeout(async () => {
      setStatus('loading');
      try {
        const data = await getWriteups({ limit: 12, q: query });
        if (!cancelled) {
          setWriteups(data.writeups || []);
          setStatus('ready');
        }
      } catch {
        if (!cancelled) {
          setWriteups([]);
          setStatus('offline');
        }
      }
    }, query ? 260 : 0);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [query]);

  useEffect(() => {
    function onKeyDown(event) {
      if (event.key === 'Escape') setActiveWriteup(null);
    }
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, []);

  return (
    <>
      <StarCanvas id="star-canvas" variant="public" />
      <PublicHeader />

      <main>
        <section className="hero" id="portal">
          <div className="hero-copy">
            <p className="kicker">Night Watch access portal</p>
            <h1>Log in, register, and publish security writeups from one clean gateway.</h1>
            <p className="hero-text">
              Enter your member workspace, create a new writer account, or browse public research notes before joining the Night Watch community.
            </p>

            <div className="hero-actions">
              <Link className="button primary" to="/login">Login to dashboard</Link>
              <Link className="button secondary" to="/register">Create account</Link>
              <a className="button ghost" href="#writeups">Browse writeups</a>
            </div>
          </div>

          <aside className="hero-card auth-portal-card" aria-label="Login and registration overview">
            <span className="tag">Member access</span>
            <h2>Choose your path</h2>
            <div className="auth-option-list">
              <Link to="/login">
                <strong>Login</strong>
                <span>Return to your private panel and manage published writeups.</span>
              </Link>
              <Link to="/register">
                <strong>Register</strong>
                <span>Create a writer account and verify your email.</span>
              </Link>
              <Link to="/forgetpassword">
                <strong>Recover</strong>
                <span>Reset access if you cannot sign in.</span>
              </Link>
            </div>
          </aside>
        </section>

        <section className="featured-section" aria-label="Featured writeup">
          <FeaturedWriteup writeup={featured} onRead={setActiveWriteup} />
        </section>

        <section className="search-section" aria-label="Search writeups">
          <label className="search-box">
            <span>Search</span>
            <input
              type="search"
              maxLength={80}
              autoComplete="off"
              placeholder="Search title, author, topic, technique..."
              value={query}
              onChange={(event) => setQuery(event.target.value)}
            />
          </label>
        </section>

        <section className="writeups" id="writeups">
          <div className="section-title">
            <p className="kicker">Community work</p>
            <h2>Fresh writeups</h2>
          </div>

          <div className="writeup-grid" aria-live="polite">
            {status === 'loading' ? (
              <article className="writeup-card loading-card">
                <span className="tag">Loading</span>
                <h3>Fetching published writeups</h3>
                <p>The feed is connected to the writeups database.</p>
              </article>
            ) : null}

            {status === 'offline' ? (
              <article className="writeup-card loading-card">
                <span className="tag">Offline</span>
                <h3>Writeups are not available</h3>
                <p>Check the database connection and the writeups schema.</p>
              </article>
            ) : null}

            {status === 'ready' ? writeups.map((writeup) => (
              <WriteupCard key={writeup.id} writeup={writeup} onRead={setActiveWriteup} />
            )) : null}
          </div>

          <p className={`empty-state${status === 'ready' && writeups.length === 0 ? ' show' : ''}`}>
            No matching writeups yet. Try another search.
          </p>
        </section>

        <section className="why" id="why">
          <div>
            <p className="kicker">How Night Watch works</p>
            <h2>Join the portal, verify your account, and publish without noise.</h2>
          </div>

          <div className="why-grid">
            <article>
              <strong>Secure account flow</strong>
              <p>Members register, verify email, and return through the login page.</p>
            </article>
            <article>
              <strong>Private writer panel</strong>
              <p>Logged-in members can manage profile details and publish writeups.</p>
            </article>
            <article>
              <strong>Public learning feed</strong>
              <p>Visitors can search educational writeups before creating an account.</p>
            </article>
          </div>
        </section>
      </main>

      <WriteupModal writeup={activeWriteup} onClose={() => setActiveWriteup(null)} />

      <footer className="site-footer">
        <span>Night Watch Community</span>
        <a href="#portal">Back to top</a>
      </footer>
    </>
  );
}
