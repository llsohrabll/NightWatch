// ========================================================================
// If the browser already has a valid session, this page is no longer needed.
// ========================================================================
(async function() {
  const session = await getSession();
  if (session) {
    window.location.replace('/panel');
  }
})();

const messageBox = document.getElementById('message-box');
const emailInput = document.getElementById('email');

// ========================================================================
// The register page stores the email in sessionStorage for convenience, but
// verification must still work after refreshes, new tabs, or another browser.
const sessionEmail = sessionStorage.getItem('registration_email');
if (sessionEmail) {
  emailInput.value = sessionEmail;
}

// ========================================================================
// Send the typed 6-digit code to the backend.
// ========================================================================
document.getElementById('verify-form').addEventListener('submit', async function (e) {
  e.preventDefault();

  const email = emailInput.value;
  const verificationCode = document.getElementById('verification_code').value.trim();

  messageBox.className = 'form-message';
  messageBox.textContent = '';

  // The backend checks this too, but early browser validation feels better.
  if (!/^\d{6}$/.test(verificationCode)) {
    messageBox.textContent = '❌ Verification code must be 6 digits.';
    return;
  }

  try {
    const formData = new FormData();
    formData.append('email', email);
    formData.append('verification_code', verificationCode);

    const response = await authFetch('/functions/verify_email.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      authRequired: false
    });

    const result = await response.json().catch(() => null);

    if (!response.ok || !result) {
      messageBox.textContent = '❌ ' + ((result && result.error) || 'Verification failed. Please try again.');
      return;
    }

    if (result.success) {
      // Cleanup: the verify page no longer needs the saved registration data.
      sessionStorage.removeItem('registration_email');
      sessionStorage.removeItem('registration_user_id');

      messageBox.className = 'form-message success';
      messageBox.textContent = '✅ ' + (result.message || 'Email verified successfully!');

      // After success, the backend session is already active.
      setTimeout(() => {
        window.location.replace(result.redirect || '/panel');
      }, 1500);
    } else {
      messageBox.textContent = '❌ ' + (result.error || 'Verification failed');
    }
  } catch (error) {
    messageBox.textContent = '❌ Network error, please try again.';
    console.error('Error:', error);
  }
});

// ========================================================================
// Ask the backend to create and email a fresh verification code.
// ========================================================================
async function resendCode(event) {
  event.preventDefault();

  const email = emailInput.value;
  if (!email) {
    messageBox.className = 'form-message';
    messageBox.textContent = '❌ Email not found.';
    return;
  }

  messageBox.className = 'form-message';
  messageBox.textContent = '';

  messageBox.className = 'form-message warning';
  messageBox.textContent = '⏳ Resending code...';

  try {
    const formData = new FormData();
    formData.append('email', email);

    const response = await authFetch('/functions/resend_verification.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      authRequired: false
    });

    const result = await response.json().catch(() => null);
    if (!response.ok || !result || !result.success) {
      messageBox.className = 'form-message';
      messageBox.textContent = '❌ ' + ((result && result.error) || 'Could not resend the code.');
      if (result && result.redirect) {
        setTimeout(() => window.location.replace(result.redirect), 1200);
      }
      return;
    }

    messageBox.className = 'form-message success';
    messageBox.textContent = '✅ ' + (result.message || 'A fresh code has been sent.');
  } catch (error) {
    messageBox.className = 'form-message';
    messageBox.textContent = '❌ Network error, please try again.';
    console.error('Error:', error);
  }
}

const resendCodeLink = document.getElementById('resend-code-link');
if (resendCodeLink) resendCodeLink.addEventListener('click', resendCode);
