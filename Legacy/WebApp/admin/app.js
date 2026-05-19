(function () {
  'use strict';

  const list = document.getElementById('moderation-list');
  const message = document.getElementById('admin-message');
  const badge = document.getElementById('admin-user-badge');
  const filters = Array.from(document.querySelectorAll('.filter-button'));
  const logoutButton = document.getElementById('logout-button');
  let currentStatus = 'pending';
  let loading = false;

  function setMessage(text, type = '') {
    message.textContent = text || '';
    message.className = `admin-message${type ? ' ' + type : ''}`;
  }

  function formatDate(value) {
    const parsed = Date.parse(String(value || '').replace(' ', 'T'));
    return Number.isNaN(parsed)
      ? ''
      : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(parsed));
  }

  function setLoading(isLoading) {
    loading = isLoading;
    filters.forEach((button) => { button.disabled = isLoading; });
  }

  function renderEmpty(text) {
    list.textContent = '';
    const empty = document.createElement('p');
    empty.className = 'admin-muted';
    empty.textContent = text;
    list.appendChild(empty);
  }

  function createActionButton(label, className, writeupId, reasonInput) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `moderation-button ${className}`;
    button.textContent = label;
    button.addEventListener('click', () => moderateWriteup(writeupId, className, reasonInput.value));
    return button;
  }

  function renderWriteups(writeups) {
    list.textContent = '';
    if (!Array.isArray(writeups) || writeups.length === 0) {
      renderEmpty(`No ${currentStatus} writeups found.`);
      return;
    }

    writeups.forEach((writeup) => {
      const card = document.createElement('article');
      card.className = 'moderation-card';

      const title = document.createElement('h2');
      title.textContent = writeup.title || 'Untitled writeup';

      const meta = document.createElement('p');
      meta.className = 'moderation-meta';
      meta.textContent = [
        `#${writeup.id}`,
        writeup.author && writeup.author.username ? `by ${writeup.author.username}` : 'unknown author',
        writeup.status || currentStatus,
        formatDate(writeup.created_at),
      ].filter(Boolean).join(' • ');

      const excerpt = document.createElement('p');
      excerpt.className = 'moderation-excerpt';
      excerpt.textContent = writeup.excerpt || 'No excerpt available.';

      const tags = document.createElement('p');
      tags.className = 'moderation-tags';
      const tagList = Array.isArray(writeup.tags) ? writeup.tags.join(', ') : '';
      tags.textContent = [writeup.category, tagList].filter(Boolean).join(' • ');

      card.append(title, meta, excerpt);
      if (tags.textContent) card.appendChild(tags);

      if (currentStatus === 'pending') {
        const actions = document.createElement('div');
        actions.className = 'moderation-actions';

        const reason = document.createElement('textarea');
        reason.className = 'moderation-reason';
        reason.maxLength = 500;
        reason.placeholder = 'Optional moderation reason, required by your process for rejections.';

        actions.append(
          createActionButton('Publish', 'publish', writeup.id, reason),
          createActionButton('Reject', 'reject', writeup.id, reason),
          reason,
        );
        card.appendChild(actions);
      }

      list.appendChild(card);
    });
  }

  async function loadQueue(status = currentStatus) {
    currentStatus = status;
    setLoading(true);
    setMessage('Loading moderation queue...');

    try {
      const response = await authFetch(`/functions/admin_writeups.php?status=${encodeURIComponent(status)}&limit=50`, {
        headers: { Accept: 'application/json' },
      });
      if (!response) return;
      if (response.status === 401) {
        await clearAuth('/login');
        return;
      }
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.success) {
        throw new Error(data && data.error ? data.error : 'Could not load moderation queue.');
      }
      renderWriteups(data.writeups || []);
      setMessage(`${data.total || 0} ${status} writeup${Number(data.total) === 1 ? '' : 's'} loaded.`, 'success');
    } catch (error) {
      renderEmpty('The moderation queue could not be loaded.');
      setMessage(error.message || 'Admin dashboard failed to load.', 'error');
    } finally {
      setLoading(false);
    }
  }

  async function moderateWriteup(writeupId, action, reason) {
    if (loading) return;
    setLoading(true);
    setMessage(`${action === 'publish' ? 'Publishing' : 'Rejecting'} writeup #${writeupId}...`);

    try {
      const formData = new FormData();
      formData.append('writeup_id', String(writeupId));
      formData.append('action', action);
      formData.append('reason', String(reason || '').trim());

      const response = await authFetch('/functions/moderate_writeup.php', {
        method: 'POST',
        body: formData,
        headers: { Accept: 'application/json' },
      });
      if (!response) return;
      if (response.status === 401) {
        await clearAuth('/login');
        return;
      }
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || !data.success) {
        throw new Error(data && data.error ? data.error : 'Moderation action failed.');
      }
      setMessage(data.message || 'Moderation action saved.', 'success');
      await loadQueue(currentStatus);
    } catch (error) {
      setMessage(error.message || 'Moderation action failed.', 'error');
    } finally {
      setLoading(false);
    }
  }

  filters.forEach((button) => {
    button.addEventListener('click', () => {
      filters.forEach((item) => item.classList.remove('active'));
      button.classList.add('active');
      loadQueue(button.dataset.status || 'pending');
    });
  });

  logoutButton.addEventListener('click', () => clearAuth('/login'));

  (async function initAdmin() {
    const session = await requireAuth('/login');
    if (!session) return;
    if (!session.is_admin) {
      badge.textContent = session.username || 'Signed in';
      renderEmpty('Administrator access is required.');
      setMessage('Your account is signed in but is not an administrator.', 'error');
      return;
    }
    badge.textContent = `Admin: ${session.username || 'member'}`;
    await loadQueue('pending');
  })();
})();
