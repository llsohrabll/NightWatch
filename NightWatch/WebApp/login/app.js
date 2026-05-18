// ========================================================================
// If this browser is already signed in, skip the login form.
(async function() {
  const session = await getSession();
  if (session) {
    // Send the user straight to the private panel.
    window.location.replace('/panel');
  }
})();

// ========================================================================
// Send the login form to the backend and show the result message.
// ========================================================================
document.getElementById('login-form').addEventListener('submit', async function (e) {
  e.preventDefault();

  const formData = new FormData(this);
  const messageBox = document.getElementById('message-box');
  messageBox.className = 'form-message';
  messageBox.textContent = '';

  try {
    const response = await authFetch('/functions/login.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      authRequired: false
    });

    const result = await response.json().catch(() => null);

    if (!response.ok || !result) {
      messageBox.textContent = '❌ ' + ((result && result.error) || 'Login failed. Please try again.');
      if (result && result.requires_email_verification && result.email) {
        sessionStorage.setItem('registration_email', result.email);
        setTimeout(() => window.location.replace(result.redirect || '/register/verify'), 900);
      }
      return;
    }

    if (result.success) {
      messageBox.className = 'form-message success';
      messageBox.textContent = result.message || '✅ Login successful! Redirecting...';

      setTimeout(() => {
        window.location.replace(result.redirect || '/panel');
      }, 700);
    } else {
      messageBox.textContent = '❌ ' + (result.error || 'Invalid username or password');
    }
  } catch (error) {
    messageBox.textContent = '❌ Network error, please try again.';
    console.error('Error:', error);
  }
});
