let tokenExpiryTimer = null;
let expiryHandled = false;

function formatDuration(msLeft) {
  const totalSeconds = Math.max(0, Math.ceil(msLeft / 1000));
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  if (hours > 0) return `${hours}h ${minutes}m ${seconds}s`;
  if (minutes > 0) return `${minutes}m ${seconds}s`;
  return `${seconds}s`;
}

function parseExpiry(value) {
  if (!value) return null;
  const normalized = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
  const parsed = Date.parse(normalized);
  return Number.isNaN(parsed) ? null : parsed;
}

function safePhotoUrl(photoPath) {
  const raw = String(photoPath || 'assets/nightwatch_symbol.png').trim();
  if (!raw) return '/assets/nightwatch_symbol.png';

  try {
    const absolute = new URL(raw);
    if (absolute.protocol === 'https:' && /\.(?:jpe?g|png)$/i.test(absolute.pathname)) {
      return absolute.href;
    }
    return '/assets/nightwatch_symbol.png';
  } catch (error) {
    // Relative path; validate below.
  }

  let normalized = raw.replace(/\\/g, '/').replace(/^\/+/, '');
  try {
    normalized = decodeURIComponent(normalized).replace(/\\/g, '/').replace(/^\/+/, '');
  } catch (error) {
    return '/assets/nightwatch_symbol.png';
  }
  if (normalized.includes('..') || normalized.includes('//')) return '/assets/nightwatch_symbol.png';
  if (normalized === 'assets/nightwatch_symbol.png') return '/assets/nightwatch_symbol.png';
  if (/^functions\/photo\.php\?file=[A-Za-z0-9._%-]+\.(?:jpe?g|png)$/i.test(normalized)) return `/${normalized}`;
  if (/^uploads\/photos\/[A-Za-z0-9._-]+\.(?:jpe?g|png)$/i.test(normalized)) return `/${normalized}`;
  return '/assets/nightwatch_symbol.png';
}

function renderTokenNotification(expiresAt) {
  const label = document.getElementById('token-expiry-label');
  const text = document.getElementById('token-expiry-text');
  if (!label || !text) return;
  if (!Number.isFinite(expiresAt)) {
    label.textContent = 'UNKNOWN';
    text.textContent = 'Token expiration could not be determined.';
    return;
  }
  const msLeft = expiresAt - Date.now();
  if (msLeft <= 0) {
    label.textContent = 'EXPIRED';
    text.textContent = 'Your token has expired. Redirecting to login...';
    return;
  }
  label.textContent = 'TOKEN';
  text.textContent = `Expires in ${formatDuration(msLeft)}.`;
}

function startTokenCountdown(expiresAt) {
  if (tokenExpiryTimer) window.clearInterval(tokenExpiryTimer);
  expiryHandled = false;
  renderTokenNotification(expiresAt);
  if (!Number.isFinite(expiresAt) || expiresAt <= Date.now()) {
    tokenExpiryTimer = null;
    if (!expiryHandled) {
      expiryHandled = true;
      window.setTimeout(() => clearAuth('/login'), 600);
    }
    return;
  }
  tokenExpiryTimer = window.setInterval(() => {
    renderTokenNotification(expiresAt);
    if (expiresAt <= Date.now()) {
      window.clearInterval(tokenExpiryTimer);
      tokenExpiryTimer = null;
      if (!expiryHandled) {
        expiryHandled = true;
        window.setTimeout(() => clearAuth('/login'), 600);
      }
    }
  }, 1000);
}

function initializeAvatar(photoPath, username) {
  const avatar = document.getElementById('profile-avatar');
  const image = document.getElementById('profile-avatar-img');
  const initials = document.getElementById('profile-avatar-initials');
  if (!avatar || !image || !initials) return;

  const photoUrl = safePhotoUrl(photoPath);
  if (photoUrl && photoUrl !== '/assets/nightwatch_symbol.png') {
    image.src = photoUrl;
    image.classList.remove('is-hidden');
    avatar.classList.add('has-photo');
    initials.textContent = '';
  } else {
    image.src = '/assets/nightwatch_symbol.png';
    image.classList.add('is-hidden');
    avatar.classList.remove('has-photo');
    initials.textContent = String(username || 'NW').substring(0, 2).toUpperCase();
  }
}

function formatDate(value) {
  const timestamp = Number(value);
  if (!Number.isFinite(timestamp)) return '';
  return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(timestamp));
}

function renderFreshWriteups(writeups) {
  const container = document.getElementById('fresh-writeups-list');
  if (!container) return;
  container.textContent = '';

  if (!Array.isArray(writeups) || writeups.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'fresh-empty';
    empty.textContent = 'No writeups have been published yet.';
    container.appendChild(empty);
    return;
  }

  writeups.slice(0, 3).forEach((writeup) => {
    const item = document.createElement('article');
    item.className = 'fresh-writeup-item';

    const title = document.createElement('h4');
    title.textContent = writeup.title || 'Untitled writeup';

    const excerpt = document.createElement('p');
    excerpt.textContent = writeup.excerpt || 'No summary available.';

    const meta = document.createElement('span');
    meta.textContent = `${writeup.author?.username || 'Member'}${writeup.published_at_ms ? ' • ' + formatDate(writeup.published_at_ms) : ''}`;

    item.append(title, excerpt, meta);
    container.appendChild(item);
  });
}

async function refreshFreshWriteups() {
  try {
    const response = await authFetch('/functions/get_writeups.php?limit=3');
    if (!response) return;
    const data = await response.json();
    if (data && data.success) renderFreshWriteups(data.writeups || []);
  } catch (error) {
    const container = document.getElementById('fresh-writeups-list');
    if (container) container.textContent = 'Could not load writeups.';
  }
}

async function loadPanelData() {
  const response = await authFetch('/functions/panel.php');
  if (!response) return null;
  const data = await response.json().catch(() => null);
  if (!data || !data.success) {
    await clearAuth('/login');
    return null;
  }

  document.querySelector('.user-name').textContent = data.username;
  const userEmail = document.getElementById('user-email');
  if (userEmail) userEmail.textContent = data.email || '';
  initializeAvatar(data.photo_path, data.username);
  renderFreshWriteups(data.latest_writeups || []);
  const expiresAt = Number(data.expires_at_ms) || parseExpiry(data.expires_at);
  startTokenCountdown(expiresAt);
  return data;
}

document.querySelector('.logout-btn').addEventListener('click', async () => {
  await clearAuth('/login');
});

async function initPanel() {
  const session = await requireAuth('/login');
  if (!session) return;
  const adminLink = document.getElementById('admin-nav-link');
  if (adminLink && session.is_admin) adminLink.classList.remove('is-hidden');
  await loadPanelData();
}

const writeupForm = document.getElementById('writeup-form');
const writeupMessage = document.getElementById('writeup-message');
const publishButton = document.getElementById('publish-writeup-btn');
const saveDraftButton = document.getElementById('save-draft-btn');

writeupForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  writeupMessage.textContent = '';
  writeupMessage.className = 'writeup-message';
  publishButton.disabled = true;
  if (saveDraftButton) saveDraftButton.disabled = true;

  try {
    const formData = new FormData(writeupForm);
    const submitter = event.submitter;
    formData.set('status', submitter?.dataset?.status || 'published');
    const response = await authFetch('/functions/create_writeup.php', {
      method: 'POST',
      body: formData,
      headers: { Accept: 'application/json' },
    });
    if (!response) return;
    if (response.status === 401) {
      await clearAuth('/login');
      return;
    }
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || !result.success) {
      throw new Error(result?.error || 'Writeup could not be published.');
    }
    writeupMessage.classList.add('success');
    writeupMessage.textContent = result.message || 'Writeup saved.';
    writeupForm.reset();
    await refreshFreshWriteups();
  } catch (error) {
    writeupMessage.classList.add('error');
    writeupMessage.textContent = error.message || 'Writeup could not be published.';
  } finally {
    publishButton.disabled = false;
    if (saveDraftButton) saveDraftButton.disabled = false;
  }
});


const emailChangeRequestForm = document.getElementById('email-change-request-form');
const emailChangeVerifyForm = document.getElementById('email-change-verify-form');
const emailChangeMessage = document.getElementById('email-change-message');
const requestEmailChangeButton = document.getElementById('request-email-change-btn');
const verifyEmailChangeButton = document.getElementById('verify-email-change-btn');
const newEmailInput = document.getElementById('new-email');
const currentPasswordInput = document.getElementById('current-password');
const emailChangeCodeInput = document.getElementById('email-change-code');

function setEmailChangeMessage(text, type = '') {
  if (!emailChangeMessage) return;
  emailChangeMessage.textContent = text || '';
  emailChangeMessage.className = `writeup-message${type ? ' ' + type : ''}`;
}

if (emailChangeRequestForm) {
  emailChangeRequestForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setEmailChangeMessage('', '');
    if (requestEmailChangeButton) requestEmailChangeButton.disabled = true;

    try {
      const formData = new FormData(emailChangeRequestForm);
      const response = await authFetch('/functions/request_email_change.php', {
        method: 'POST',
        body: formData,
        headers: { Accept: 'application/json' },
      });
      if (!response) return;
      if (response.status === 401) {
        const result = await response.json().catch(() => null);
        if (result && result.error && !/unauthorized/i.test(result.error)) {
          throw new Error(result.error);
        }
        await clearAuth('/login');
        return;
      }
      const result = await response.json().catch(() => null);
      if (!response.ok || !result || !result.success) {
        throw new Error(result?.error || 'Could not start email change.');
      }
      setEmailChangeMessage(result.message || 'Confirmation code sent to your new email.', result.email_sent === false ? 'error' : 'success');
      if (emailChangeVerifyForm) emailChangeVerifyForm.classList.remove('is-hidden');
      if (currentPasswordInput) currentPasswordInput.value = '';
      if (emailChangeCodeInput) emailChangeCodeInput.focus();
    } catch (error) {
      setEmailChangeMessage(error.message || 'Could not start email change.', 'error');
    } finally {
      if (requestEmailChangeButton) requestEmailChangeButton.disabled = false;
    }
  });
}

if (emailChangeVerifyForm) {
  emailChangeVerifyForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setEmailChangeMessage('', '');
    if (verifyEmailChangeButton) verifyEmailChangeButton.disabled = true;

    try {
      const formData = new FormData(emailChangeVerifyForm);
      const response = await authFetch('/functions/verify_email_change.php', {
        method: 'POST',
        body: formData,
        headers: { Accept: 'application/json' },
      });
      if (!response) return;
      if (response.status === 401) {
        const result = await response.json().catch(() => null);
        if (result && result.error && !/unauthorized/i.test(result.error)) {
          throw new Error(result.error);
        }
        await clearAuth('/login');
        return;
      }
      const result = await response.json().catch(() => null);
      if (!response.ok || !result || !result.success) {
        throw new Error(result?.error || 'Could not verify the email change.');
      }
      const userEmail = document.getElementById('user-email');
      if (userEmail) userEmail.textContent = result.email || newEmailInput?.value || '';
      setEmailChangeMessage(result.message || 'Email address updated.', 'success');
      emailChangeVerifyForm.classList.add('is-hidden');
      emailChangeVerifyForm.reset();
      if (emailChangeRequestForm) emailChangeRequestForm.reset();
    } catch (error) {
      setEmailChangeMessage(error.message || 'Could not verify the email change.', 'error');
    } finally {
      if (verifyEmailChangeButton) verifyEmailChangeButton.disabled = false;
    }
  });
}

const PHOTO_CONFIG = {
  maxSize: 500 * 1024,
  allowedTypes: ['jpg', 'jpeg', 'png'],
  allowedMimes: ['image/jpeg', 'image/jpg', 'image/png']
};

const photoModal = document.getElementById('photo-upload-modal');
const photoForm = document.getElementById('photo-upload-form');
const photoInput = document.getElementById('photo-input');
const fileInputLabel = document.querySelector('.file-input-label');
const filePreviewWrapper = document.getElementById('file-preview-wrapper');
const filePreview = document.getElementById('file-preview');
const removeFileBtn = document.getElementById('remove-file-btn');
const uploadError = document.getElementById('upload-error');
const uploadProgress = document.getElementById('upload-progress');
const progressFill = document.getElementById('progress-fill');
const progressPercent = document.getElementById('progress-percent');
const submitBtn = document.getElementById('submit-upload');
const photoEditBtn = document.getElementById('photo-edit-btn');
const closePhotoModal = document.getElementById('close-photo-modal');
const cancelUpload = document.getElementById('cancel-upload');
let selectedFile = null;
let isUploading = false;

function closePhotoModalFn() {
  photoModal.classList.remove('show');
  resetUploadForm();
}

photoEditBtn.addEventListener('click', () => {
  photoModal.classList.add('show');
  resetUploadForm();
});
closePhotoModal.addEventListener('click', closePhotoModalFn);
cancelUpload.addEventListener('click', closePhotoModalFn);
photoModal.addEventListener('click', (event) => {
  if (event.target === photoModal) closePhotoModalFn();
});

photoInput.addEventListener('change', (event) => {
  const file = event.target.files?.[0];
  if (file) validateAndPreviewFile(file);
});

removeFileBtn.addEventListener('click', () => {
  selectedFile = null;
  photoInput.value = '';
  filePreviewWrapper.classList.add('is-hidden');
  fileInputLabel.classList.remove('is-hidden');
  uploadError.classList.add('is-hidden');
});

fileInputLabel.addEventListener('dragover', (event) => {
  event.preventDefault();
  fileInputLabel.classList.add('drag-over');
});
fileInputLabel.addEventListener('dragleave', () => fileInputLabel.classList.remove('drag-over'));
fileInputLabel.addEventListener('drop', (event) => {
  event.preventDefault();
  fileInputLabel.classList.remove('drag-over');
  const file = event.dataTransfer?.files?.[0];
  if (file) validateAndPreviewFile(file);
});

photoForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!selectedFile || isUploading) return;
  await uploadPhoto();
});

function validateAndPreviewFile(file) {
  uploadError.classList.add('is-hidden');
  uploadError.textContent = '';
  const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
  if (!(file instanceof File) || !file.name || file.size <= 0) return showError('Please choose a valid image file.');
  if (!PHOTO_CONFIG.allowedTypes.includes(extension)) return showError('Only JPG, JPEG, and PNG files are allowed.');
  if (file.size > PHOTO_CONFIG.maxSize) return showError('File size exceeds 500KB.');
  if (!PHOTO_CONFIG.allowedMimes.includes(file.type)) return showError('Only JPG/JPEG/PNG images are allowed.');
  selectedFile = file;
  const reader = new FileReader();
  reader.onload = (event) => {
    filePreview.src = event.target?.result ?? '';
    filePreviewWrapper.classList.remove('is-hidden');
    fileInputLabel.classList.add('is-hidden');
  };
  reader.readAsDataURL(file);
}

function showError(message) {
  uploadError.textContent = message;
  uploadError.classList.remove('is-hidden');
  selectedFile = null;
  photoInput.value = '';
  filePreviewWrapper.classList.add('is-hidden');
  fileInputLabel.classList.remove('is-hidden');
}

async function uploadPhoto() {
  isUploading = true;
  submitBtn.disabled = true;
  uploadProgress.classList.remove('is-hidden');
  uploadError.classList.add('is-hidden');
  const formData = new FormData();
  formData.append('photo', selectedFile);

  try {
    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) {
        const percent = Math.max(0, Math.min(100, (event.loaded / event.total) * 100));
        progressFill.value = Math.round(percent);
        progressPercent.textContent = String(Math.round(percent));
      }
    });
    xhr.addEventListener('load', async () => {
      if (xhr.status === 401) {
        completeUpload();
        await clearAuth('/login');
        return;
      }
      let response = {};
      try {
        response = JSON.parse(xhr.responseText || '{}');
      } catch (error) {
        response = {};
      }
      if (xhr.status === 200 && response.success && safePhotoUrl(response.photo_path)) {
        initializeAvatar(response.photo_path, document.querySelector('.user-name').textContent);
        window.setTimeout(closePhotoModalFn, 400);
      } else {
        showUploadError(response.error || 'Upload failed.');
      }
      completeUpload();
    });
    xhr.addEventListener('error', () => {
      showUploadError('Network error. Please try again.');
      completeUpload();
    });
    xhr.open('POST', '/functions/upload_photo.php');
    xhr.withCredentials = true;
    const csrfToken = await getCsrfToken();
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(formData);
  } catch (error) {
    showUploadError('An error occurred. Please try again.');
    completeUpload();
  }
}

function showUploadError(message) {
  uploadError.textContent = message;
  uploadError.classList.remove('is-hidden');
  uploadProgress.classList.add('is-hidden');
}

function completeUpload() {
  isUploading = false;
  submitBtn.disabled = false;
  uploadProgress.classList.add('is-hidden');
  progressFill.value = 0;
  progressPercent.textContent = '0';
}

function resetUploadForm() {
  selectedFile = null;
  photoInput.value = '';
  filePreviewWrapper.classList.add('is-hidden');
  fileInputLabel.classList.remove('is-hidden');
  uploadError.classList.add('is-hidden');
  uploadProgress.classList.add('is-hidden');
  progressFill.value = 0;
  progressPercent.textContent = '0';
  submitBtn.disabled = false;
  isUploading = false;
}

initPanel();
