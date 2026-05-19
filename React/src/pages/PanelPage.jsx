import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import StarCanvas from '../components/StarCanvas.jsx';
import useBodyClass from '../hooks/useBodyClass.js';
import { authFetch, clearAuth, getCsrfToken, getSession, readJsonSafe } from '../api/auth.js';
import { getPanelData, getWriteups } from '../api/nightwatch.js';
import { formatDate, formatDuration, parseExpiry } from '../utils/date.js';
import { isDefaultPhoto, safePhotoUrl } from '../utils/photo.js';

const PHOTO_CONFIG = {
  maxSize: 500 * 1024,
  allowedTypes: ['jpg', 'jpeg', 'png'],
  allowedMimes: ['image/jpeg', 'image/jpg', 'image/png'],
};

function Avatar({ username, photoPath, onEdit }) {
  const photoUrl = safePhotoUrl(photoPath);
  const hasPhoto = !isDefaultPhoto(photoUrl);
  const initials = String(username || 'NW').substring(0, 2).toUpperCase();

  return (
    <div className="avatar-wrapper">
      <span className="avatar-orbit orbit-a" aria-hidden="true" />
      <span className="avatar-orbit orbit-b" aria-hidden="true" />
      <div className={`avatar${hasPhoto ? ' has-photo' : ''}`}>
        {hasPhoto ? <img src={photoUrl} alt="" className="avatar-image" /> : <span>{initials}</span>}
      </div>
      <button className="avatar-edit-btn" type="button" title="Edit photo" aria-label="Edit profile photo" onClick={onEdit}>
        <span className="edit-icon">+</span>
      </button>
    </div>
  );
}

function TokenStatus({ expiresAt }) {
  const [now, setNow] = useState(Date.now());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    if (Number.isFinite(expiresAt) && expiresAt <= Date.now()) {
      const timer = window.setTimeout(() => clearAuth('/login'), 600);
      return () => window.clearTimeout(timer);
    }
    return undefined;
  }, [expiresAt, now]);

  if (!Number.isFinite(expiresAt)) {
    return (
      <>
        <h3>UNKNOWN</h3>
        <p>Token expiration could not be determined.</p>
      </>
    );
  }

  const msLeft = expiresAt - now;
  if (msLeft <= 0) {
    return (
      <>
        <h3>EXPIRED</h3>
        <p>Your token has expired. Redirecting to login...</p>
      </>
    );
  }

  return (
    <>
      <h3>TOKEN</h3>
      <p>Expires in {formatDuration(msLeft)}.</p>
    </>
  );
}

function FreshWriteups({ writeups }) {
  if (!Array.isArray(writeups)) {
    return <div className="loading-spinner">Loading writeups...</div>;
  }

  if (!writeups.length) {
    return <p className="fresh-empty">No writeups have been published yet.</p>;
  }

  return writeups.slice(0, 3).map((writeup) => (
    <article className="fresh-writeup-item" key={writeup.id}>
      <h4>{writeup.title || 'Untitled writeup'}</h4>
      <p>{writeup.excerpt || 'No summary available.'}</p>
      <span>
        {writeup.author?.username || 'Member'}
        {writeup.published_at_ms ? ` / ${formatDate(writeup.published_at_ms)}` : ''}
      </span>
    </article>
  ));
}

function PhotoUploadModal({ open, onClose, username, onUploaded }) {
  const inputRef = useRef(null);
  const [selectedFile, setSelectedFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState('');
  const [error, setError] = useState('');
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [dragOver, setDragOver] = useState(false);

  useEffect(() => {
    return () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl);
    };
  }, [previewUrl]);

  function reset() {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    setSelectedFile(null);
    setPreviewUrl('');
    setError('');
    setProgress(0);
    setUploading(false);
    if (inputRef.current) inputRef.current.value = '';
  }

  function close() {
    reset();
    onClose();
  }

  function showError(message) {
    setError(message);
    setSelectedFile(null);
    setPreviewUrl('');
    if (inputRef.current) inputRef.current.value = '';
  }

  function validateAndPreviewFile(file) {
    setError('');
    const extension = file?.name?.split('.').pop()?.toLowerCase() ?? '';
    if (!(file instanceof File) || !file.name || file.size <= 0) return showError('Please choose a valid image file.');
    if (!PHOTO_CONFIG.allowedTypes.includes(extension)) return showError('Only JPG, JPEG, and PNG files are allowed.');
    if (file.size > PHOTO_CONFIG.maxSize) return showError('File size exceeds 500KB.');
    if (!PHOTO_CONFIG.allowedMimes.includes(file.type)) return showError('Only JPG/JPEG/PNG images are allowed.');

    if (previewUrl) URL.revokeObjectURL(previewUrl);
    setSelectedFile(file);
    setPreviewUrl(URL.createObjectURL(file));
  }

  async function uploadPhoto(event) {
    event.preventDefault();
    if (!selectedFile || uploading) return;

    setUploading(true);
    setError('');
    setProgress(0);

    const formData = new FormData();
    formData.append('photo', selectedFile);

    try {
      const csrfToken = await getCsrfToken();
      const xhr = new XMLHttpRequest();

      xhr.upload.addEventListener('progress', (progressEvent) => {
        if (progressEvent.lengthComputable) {
          const percent = Math.max(0, Math.min(100, (progressEvent.loaded / progressEvent.total) * 100));
          setProgress(Math.round(percent));
        }
      });

      xhr.addEventListener('load', async () => {
        if (xhr.status === 401) {
          setUploading(false);
          await clearAuth('/login');
          return;
        }

        let response = {};
        try {
          response = JSON.parse(xhr.responseText || '{}');
        } catch {
          response = {};
        }

        if (xhr.status === 200 && response.success && safePhotoUrl(response.photo_path)) {
          onUploaded(response.photo_path, username);
          window.setTimeout(close, 400);
        } else {
          setError(response.error || 'Upload failed.');
        }
        setUploading(false);
        setProgress(0);
      });

      xhr.addEventListener('error', () => {
        setError('Network error. Please try again.');
        setUploading(false);
        setProgress(0);
      });

      xhr.open('POST', '/functions/upload_photo.php');
      xhr.withCredentials = true;
      if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.send(formData);
    } catch {
      setError('An error occurred. Please try again.');
      setUploading(false);
      setProgress(0);
    }
  }

  if (!open) return null;

  return (
    <div className="modal show" onMouseDown={(event) => {
      if (event.target === event.currentTarget) close();
    }}>
      <div className="modal-content photo-upload-modal">
        <div className="modal-header">
          <div>
            <p className="eyebrow">Profile image</p>
            <h3>Upload avatar</h3>
          </div>
          <button className="modal-close" type="button" aria-label="Close photo upload modal" onClick={close}>x</button>
        </div>
        <div className="modal-body">
          <div className="upload-info">
            <p>Allowed formats: <strong>JPG, JPEG, PNG</strong></p>
            <p>Maximum size: <strong>500KB</strong></p>
          </div>

          <form className="photo-upload-form" onSubmit={uploadPhoto}>
            <div className="file-input-wrapper">
              <input
                ref={inputRef}
                id="photo-input"
                type="file"
                name="photo"
                accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                className="visually-hidden-file"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) validateAndPreviewFile(file);
                }}
              />

              {!selectedFile ? (
                <label
                  htmlFor="photo-input"
                  className={`file-input-label${dragOver ? ' drag-over' : ''}`}
                  onDragOver={(event) => {
                    event.preventDefault();
                    setDragOver(true);
                  }}
                  onDragLeave={() => setDragOver(false)}
                  onDrop={(event) => {
                    event.preventDefault();
                    setDragOver(false);
                    const file = event.dataTransfer?.files?.[0];
                    if (file) validateAndPreviewFile(file);
                  }}
                >
                  <span className="file-icon">+</span>
                  <span className="file-text">Choose an avatar image or drag it here</span>
                </label>
              ) : null}

              {selectedFile ? (
                <div className="file-preview-wrapper">
                  <img src={previewUrl} className="file-preview" alt="Preview" />
                  <button type="button" className="remove-file-btn" onClick={reset}>Remove</button>
                </div>
              ) : null}
            </div>

            {error ? <div className="upload-error">{error}</div> : null}

            <div className="modal-actions">
              <button type="button" className="btn-cancel" onClick={close}>Cancel</button>
              <button type="submit" className="btn-upload" disabled={!selectedFile || uploading}>
                {uploading ? 'Uploading...' : 'Upload photo'}
              </button>
            </div>
          </form>

          {uploading ? (
            <div className="upload-progress">
              <div className="progress-bar">
                <progress className="progress-fill" max="100" value={progress} aria-label="Upload progress" />
              </div>
              <p className="progress-text">Uploading... <span>{progress}</span>%</p>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}

export default function PanelPage() {
  useBodyClass('panel-screen');
  const [session, setSession] = useState(null);
  const [profile, setProfile] = useState({ username: 'Loading...', email: '', photo_path: 'assets/nightwatch_symbol.png' });
  const [latestWriteups, setLatestWriteups] = useState(null);
  const [expiresAt, setExpiresAt] = useState(null);
  const [writeupMessage, setWriteupMessage] = useState(null);
  const [emailMessage, setEmailMessage] = useState(null);
  const [emailVerifyVisible, setEmailVerifyVisible] = useState(false);
  const [photoModalOpen, setPhotoModalOpen] = useState(false);
  const [savingWriteup, setSavingWriteup] = useState(false);
  const [emailSubmitting, setEmailSubmitting] = useState(false);
  const writeupFormRef = useRef(null);
  const emailRequestFormRef = useRef(null);
  const emailVerifyFormRef = useRef(null);

  const userProfileHref = useMemo(() => `/users?username=${encodeURIComponent(profile.username || '')}`, [profile.username]);

  useEffect(() => {
    let cancelled = false;

    async function initPanel() {
      const activeSession = await getSession(true);
      if (!activeSession) {
        window.location.replace('/login');
        return;
      }
      if (!cancelled) setSession(activeSession);

      try {
        const data = await getPanelData();
        if (cancelled) return;
        setProfile({
          username: data.username || activeSession.username || 'Member',
          email: data.email || '',
          photo_path: data.photo_path || 'assets/nightwatch_symbol.png',
        });
        setLatestWriteups(data.latest_writeups || []);
        setExpiresAt(Number(data.expires_at_ms) || parseExpiry(data.expires_at));
      } catch {
        await clearAuth('/login');
      }
    }

    initPanel();
    return () => {
      cancelled = true;
    };
  }, []);

  async function refreshFreshWriteups() {
    try {
      const data = await getWriteups({ limit: 3 });
      setLatestWriteups(data.writeups || []);
    } catch {
      setLatestWriteups([]);
    }
  }

  async function saveWriteup(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submitter = event.nativeEvent.submitter;
    setSavingWriteup(true);
    setWriteupMessage(null);

    try {
      const formData = new FormData(form);
      formData.set('status', submitter?.dataset?.status || 'published');
      const response = await authFetch('/functions/create_writeup.php', {
        method: 'POST',
        body: formData,
        headers: { Accept: 'application/json' },
      });
      if (!response) throw new Error('Network error, please try again.');
      if (response.status === 401) {
        await clearAuth('/login');
        return;
      }
      const result = await readJsonSafe(response);
      if (!response.ok || !result || !result.success) {
        throw new Error(result?.error || 'Writeup could not be published.');
      }
      setWriteupMessage({ type: 'success', text: result.message || 'Writeup saved.' });
      form.reset();
      await refreshFreshWriteups();
    } catch (error) {
      setWriteupMessage({ type: 'error', text: error.message || 'Writeup could not be published.' });
    } finally {
      setSavingWriteup(false);
    }
  }

  async function requestEmailChange(event) {
    event.preventDefault();
    setEmailSubmitting(true);
    setEmailMessage(null);

    try {
      const formData = new FormData(event.currentTarget);
      const response = await authFetch('/functions/request_email_change.php', {
        method: 'POST',
        body: formData,
        headers: { Accept: 'application/json' },
      });
      if (!response) throw new Error('Network error, please try again.');
      if (response.status === 401) {
        const result = await readJsonSafe(response);
        if (result && result.error && !/unauthorized/i.test(result.error)) throw new Error(result.error);
        await clearAuth('/login');
        return;
      }
      const result = await readJsonSafe(response);
      if (!response.ok || !result || !result.success) {
        throw new Error(result?.error || 'Could not start email change.');
      }
      setEmailMessage({
        type: result.email_sent === false ? 'error' : 'success',
        text: result.message || 'Confirmation code sent to your new email.',
      });
      setEmailVerifyVisible(true);
      event.currentTarget.current_password.value = '';
      window.setTimeout(() => emailVerifyFormRef.current?.verification_code?.focus(), 0);
    } catch (error) {
      setEmailMessage({ type: 'error', text: error.message || 'Could not start email change.' });
    } finally {
      setEmailSubmitting(false);
    }
  }

  async function verifyEmailChange(event) {
    event.preventDefault();
    setEmailSubmitting(true);
    setEmailMessage(null);

    try {
      const formData = new FormData(event.currentTarget);
      const response = await authFetch('/functions/verify_email_change.php', {
        method: 'POST',
        body: formData,
        headers: { Accept: 'application/json' },
      });
      if (!response) throw new Error('Network error, please try again.');
      if (response.status === 401) {
        const result = await readJsonSafe(response);
        if (result && result.error && !/unauthorized/i.test(result.error)) throw new Error(result.error);
        await clearAuth('/login');
        return;
      }
      const result = await readJsonSafe(response);
      if (!response.ok || !result || !result.success) {
        throw new Error(result?.error || 'Could not verify the email change.');
      }
      setProfile((current) => ({ ...current, email: result.email || emailRequestFormRef.current?.new_email?.value || current.email }));
      setEmailMessage({ type: 'success', text: result.message || 'Email address updated.' });
      setEmailVerifyVisible(false);
      emailVerifyFormRef.current?.reset();
      emailRequestFormRef.current?.reset();
    } catch (error) {
      setEmailMessage({ type: 'error', text: error.message || 'Could not verify the email change.' });
    } finally {
      setEmailSubmitting(false);
    }
  }

  return (
    <>
      <StarCanvas id="space-canvas" className="bg-canvas" variant="panel" />
      <div className="cosmic-noise" aria-hidden="true" />
      <div className="aurora aurora-one" aria-hidden="true" />
      <div className="aurora aurora-two" aria-hidden="true" />

      <div className="panel-shell">
        <aside className="sidebar glass-panel reveal-card">
          <Link to="/" className="brand-block">
            <img src="/assets/nightwatch_symbol.png" alt="Night Watch" className="brand-logo" />
            <div>
              <p className="eyebrow">Night Watch</p>
              <h2>Writeup Panel</h2>
            </div>
          </Link>

          <div className="profile-section">
            <Avatar username={profile.username} photoPath={profile.photo_path} onEdit={() => setPhotoModalOpen(true)} />
            <p className="profile-label">Signed in as</p>
            <h1 className="user-name">{profile.username}</h1>
            <p className="user-email">{profile.email || 'Checking email...'}</p>
            <Link className="user-rank profile-link" to={userProfileHref}>Public profile</Link>
          </div>

          <nav className="side-nav" aria-label="Panel navigation">
            <a href="#writeup-editor" className="nav-btn active">
              <span className="nav-icon">W</span>
              <span><strong>Write</strong><small>Publish a writeup</small></span>
            </a>
            <a href="#fresh-writeups" className="nav-btn">
              <span className="nav-icon">F</span>
              <span><strong>Fresh writeups</strong><small>Latest 3 posts</small></span>
            </a>
            <a href="#account-settings" className="nav-btn">
              <span className="nav-icon">@</span>
              <span><strong>Account</strong><small>Change verified email</small></span>
            </a>
            {session?.is_admin ? (
              <Link to="/admin" className="nav-btn">
                <span className="nav-icon">A</span>
                <span><strong>Admin</strong><small>Moderate writeups</small></span>
              </Link>
            ) : null}
          </nav>

          <div className="sidebar-note">
            <span className="note-dot" />
            <p>Publish educational, authorized research. Do not share live secrets or private targets.</p>
          </div>

          <button className="logout-btn" type="button" onClick={() => clearAuth('/login')}>Log out</button>
        </aside>

        <main className="main-content">
          <header className="top-bar glass-panel reveal-card">
            <div>
              <p className="eyebrow">Member access</p>
              <h2 className="panel-title">Publish clean security writeups.</h2>
              <p className="panel-subtitle">Your session is verified with a server-side token before this panel loads.</p>
            </div>
            <div className="status-indicator">
              <span className="pulse-dot" /> Session active
            </div>
          </header>

          <section className="hero-grid panel-writeup-grid">
            <article className="mission-card glass-panel reveal-card" id="writeup-editor">
              <div className="mission-copy writeup-editor-copy">
                <p className="eyebrow">New writeup</p>
                <h3>Document what you found and what others can learn.</h3>
                <p>Use this for CTFs, labs, defensive lessons, and responsible research notes.</p>

                <form ref={writeupFormRef} className="writeup-form" onSubmit={saveWriteup}>
                  <label>
                    <span>Title</span>
                    <input type="text" name="title" maxLength={140} placeholder="Example: SQL injection lab walkthrough" required />
                  </label>
                  <label>
                    <span>Category</span>
                    <input type="text" name="category" maxLength={40} placeholder="web-security, ctf, blue-team" />
                  </label>
                  <label>
                    <span>Tags</span>
                    <input type="text" name="tags" maxLength={255} placeholder="sql-injection, auth, logging" />
                  </label>
                  <label>
                    <span>Markdown body</span>
                    <textarea name="content_md" rows={12} maxLength={20000} placeholder="Write the goal, recon steps, failed attempts, finding, fix, and lessons learned." required />
                  </label>
                  <p className="markdown-policy">Allowed Markdown: headings, lists, links, code blocks, emphasis, and tables. Raw scripts, iframes, forms, and embedded objects are rejected.</p>
                  <div className="writeup-form-actions">
                    <button type="submit" className="publish-btn" data-status="published" disabled={savingWriteup}>Publish writeup</button>
                    <button type="submit" className="publish-btn secondary" data-status="draft" disabled={savingWriteup}>Save draft</button>
                    <p className={`writeup-message${writeupMessage?.type ? ` ${writeupMessage.type}` : ''}`} aria-live="polite">
                      {writeupMessage?.text || ''}
                    </p>
                  </div>
                </form>
              </div>
            </article>

            <article className="session-card glass-panel reveal-card">
              <div className="session-icon" aria-hidden="true">O</div>
              <p className="eyebrow">Session lock</p>
              <TokenStatus expiresAt={expiresAt} />
            </article>
          </section>

          <section className="activity-log glass-panel reveal-card" id="fresh-writeups">
            <div className="section-heading">
              <div>
                <p className="eyebrow">Fresh writeups</p>
                <h3>Latest 3 community posts</h3>
              </div>
              <span className="tiny-badge">Database feed</span>
            </div>
            <div className="fresh-writeups-list" aria-live="polite">
              <FreshWriteups writeups={latestWriteups} />
            </div>
          </section>

          <section className="activity-log glass-panel reveal-card" id="account-settings">
            <div className="section-heading">
              <div>
                <p className="eyebrow">Account security</p>
                <h3>Change your email safely</h3>
              </div>
              <span className="tiny-badge">Verified change</span>
            </div>

            <div className="account-settings-grid">
              <form ref={emailRequestFormRef} className="writeup-form account-form" onSubmit={requestEmailChange}>
                <label>
                  <span>New email address</span>
                  <input type="email" name="new_email" maxLength={255} placeholder="new-address@example.com" autoComplete="email" required />
                </label>
                <label>
                  <span>Current password</span>
                  <input type="password" name="current_password" autoComplete="current-password" required />
                </label>
                <div className="writeup-form-actions">
                  <button type="submit" className="publish-btn" disabled={emailSubmitting}>Send confirmation code</button>
                </div>
              </form>

              {emailVerifyVisible ? (
                <form ref={emailVerifyFormRef} className="writeup-form account-form" onSubmit={verifyEmailChange}>
                  <label>
                    <span>6-digit confirmation code</span>
                    <input type="text" name="verification_code" maxLength={6} pattern="\d{6}" inputMode="numeric" autoComplete="one-time-code" placeholder="123456" required />
                  </label>
                  <div className="writeup-form-actions">
                    <button type="submit" className="publish-btn secondary" disabled={emailSubmitting}>Confirm new email</button>
                  </div>
                </form>
              ) : null}
            </div>
            <p className={`writeup-message${emailMessage?.type ? ` ${emailMessage.type}` : ''}`} aria-live="polite">
              {emailMessage?.text || ''}
            </p>
          </section>
        </main>
      </div>

      <PhotoUploadModal
        open={photoModalOpen}
        onClose={() => setPhotoModalOpen(false)}
        username={profile.username}
        onUploaded={(photoPath) => setProfile((current) => ({ ...current, photo_path: photoPath }))}
      />
    </>
  );
}
