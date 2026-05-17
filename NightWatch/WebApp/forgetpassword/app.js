// Signed-in users do not need the reset flow.
(async function() {
  const session = await getSession();
  if (session) {
    window.location.replace('/panel');
  }
})();

const messageBox = document.getElementById('message-box');
const requestForm = document.getElementById('request-form');
const resetForm = document.getElementById('reset-form');
const requestEmail = document.getElementById('request-email');
const resetEmail = document.getElementById('reset-email');

// ========================================================================
// Step 1 asks the server to email a one-time reset code.
// ========================================================================
document.getElementById('request-form').addEventListener('submit', async function (e) {
  e.preventDefault();

  const email = requestEmail.value.trim();
  messageBox.className = 'form-message';
  messageBox.textContent = '';

  try {
    const formData = new FormData();
    formData.append('email', email);

    const response = await authFetch('/functions/forget_password.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      authRequired: false
    });

    const result = await response.json().catch(() => null);

    if (!response.ok || !result) {
      messageBox.textContent = '❌ ' + ((result && result.error) || 'Request failed. Please try again.');
      return;
    }

    if (result.success) {
      messageBox.className = 'form-message success';
      messageBox.textContent = '✅ ' + (result.message || 'Code sent to your email!');

      // Switch the UI to the second form after the request succeeds.
      setTimeout(() => {
        resetEmail.value = email;
        requestForm.classList.add('is-hidden');
        resetForm.classList.remove('is-hidden');
        messageBox.textContent = '';
      }, 1500);
    } else {
      messageBox.textContent = '❌ ' + (result.error || 'Request failed');
    }
  } catch (error) {
    messageBox.textContent = '❌ Network error, please try again.';
    console.error('Error:', error);
  }
});

// ========================================================================
// Step 2 sends the code and the new password to the backend.
// ========================================================================
document.getElementById('reset-form').addEventListener('submit', async function (e) {
  e.preventDefault();

  const email = resetEmail.value;
  const resetCode = document.getElementById('reset-code').value.trim();
  const newPassword = document.getElementById('new-password').value;
  const confirmPassword = document.getElementById('confirm-password').value;

  messageBox.className = 'form-message';
  messageBox.textContent = '';

  // Quick browser-side validation before the request is sent.
  if (!/^\d{6}$/.test(resetCode)) {
    messageBox.textContent = '❌ Reset code must be 6 digits.';
    return;
  }

  // The two password boxes must match.
  if (newPassword !== confirmPassword) {
    messageBox.textContent = '❌ Passwords do not match.';
    return;
  }

  // Keep the same minimum length rule used by the backend.
  if (newPassword.length < 10 || !/[a-z]/.test(newPassword) || !/[A-Z]/.test(newPassword) || !/\d/.test(newPassword)) {
    messageBox.textContent = '❌ Password must be at least 10 characters and include uppercase, lowercase, and a number.';
    return;
  }

  try {
    const formData = new FormData();
    formData.append('email', email);
    formData.append('reset_code', resetCode);
    formData.append('new_password', newPassword);
    formData.append('confirm_password', confirmPassword);

    const response = await authFetch('/functions/reset_password.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      authRequired: false
    });

    const result = await response.json().catch(() => null);

    if (!response.ok || !result) {
      messageBox.textContent = '❌ ' + ((result && result.error) || 'Reset failed. Please try again.');
      return;
    }

    if (result.success) {
      messageBox.className = 'form-message success';
      messageBox.textContent = '✅ ' + (result.message || 'Password reset successful!');

      // After success the user signs in with the new password from the login page.
      setTimeout(() => {
        window.location.replace('/login');
      }, 2000);
    } else {
      messageBox.textContent = '❌ ' + (result.error || 'Reset failed');
    }
  } catch (error) {
    messageBox.textContent = '❌ Network error, please try again.';
    console.error('Error:', error);
  }
});

// ========================================================================
// Let the user restart the flow without reloading the page.
// ========================================================================
function resetToStep1() {
  requestForm.classList.remove('is-hidden');
  resetForm.classList.add('is-hidden');
  messageBox.textContent = '';
  document.getElementById('reset-code').value = '';
  document.getElementById('new-password').value = '';
  document.getElementById('confirm-password').value = '';
}

const backToStep1Button = document.getElementById('back-to-step1');
if (backToStep1Button) backToStep1Button.addEventListener('click', resetToStep1);
