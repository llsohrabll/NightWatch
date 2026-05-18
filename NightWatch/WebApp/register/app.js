// ========================================================================
// If this browser is already signed in, skip the registration form.
(async function() {
  const session = await getSession();
  if (session) {
    // Send the user straight to the private panel.
    window.location.replace('/panel');
  }
})();

// ========================================================================
// Send the registration form, then move to email verification on success.
// ========================================================================
document.getElementById('register-form').addEventListener('submit', async function (e) {
  e.preventDefault();

  const email = document.getElementById('email').value;
  const password = document.getElementById('password').value;
  const confirmPassword = document.getElementById('confirm_password').value;
  const messageBox = document.getElementById('message-box');
  messageBox.className = 'form-message';
  messageBox.textContent = '';

  if (password !== confirmPassword) {
    messageBox.textContent = '❌ Passwords do not match';
    return;
  }

  const formData = new FormData(this);
  formData.delete('confirm_password');

  try {
    const response = await authFetch('/functions/register.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      authRequired: false
    });

    const result = await response.json().catch(() => null);

    if (!response.ok || !result) {
      messageBox.textContent = '❌ ' + ((result && result.error) || 'Registration failed. Please try again.');
      return;
    }

    if (result.success) {
      messageBox.className = 'form-message success';
      messageBox.textContent = result.message || '✅ Registration successful! Redirecting...';

      // Save the email locally so the verify page can prefill it.
      if (result.requires_email_verification) {
        sessionStorage.setItem('registration_email', email);
        if (result.user_id) {
          sessionStorage.setItem('registration_user_id', result.user_id);
        }
      }

      setTimeout(() => {
        window.location.replace(result.redirect || '/register/verify');
      }, 1000);
    } else {
      messageBox.textContent = '❌ ' + (result.error || 'Unknown error');
    }
  } catch (error) {
    messageBox.textContent = '❌ Network error, please try again.';
    console.error('Error:', error);
  }
});
