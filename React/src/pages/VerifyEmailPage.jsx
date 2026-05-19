import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AuthShell from '../components/AuthShell.jsx';
import FormMessage from '../components/FormMessage.jsx';
import { authFetch, getSession, readJsonSafe } from '../api/auth.js';

export default function VerifyEmailPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState(() => sessionStorage.getItem('registration_email') || '');
  const [message, setMessage] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let mounted = true;
    getSession().then((session) => {
      if (mounted && session) navigate('/panel', { replace: true });
    });
    return () => {
      mounted = false;
    };
  }, [navigate]);

  async function onSubmit(event) {
    event.preventDefault();
    const verificationCode = event.currentTarget.verification_code.value.trim();
    setMessage(null);

    if (!/^\d{6}$/.test(verificationCode)) {
      setMessage({ text: 'Verification code must be 6 digits.' });
      return;
    }

    setSubmitting(true);
    try {
      const formData = new FormData();
      formData.append('email', email);
      formData.append('verification_code', verificationCode);
      const response = await authFetch('/functions/verify_email.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        authRequired: false,
      });
      const result = response ? await readJsonSafe(response) : null;

      if (!response || !response.ok || !result) {
        setMessage({ text: (result && result.error) || 'Verification failed. Please try again.' });
        return;
      }

      if (result.success) {
        sessionStorage.removeItem('registration_email');
        sessionStorage.removeItem('registration_user_id');
        setMessage({ type: 'success', text: result.message || 'Email verified successfully.' });
        window.setTimeout(() => navigate(result.redirect || '/panel', { replace: true }), 1500);
      } else {
        setMessage({ text: result.error || 'Verification failed.' });
      }
    } catch {
      setMessage({ text: 'Network error, please try again.' });
    } finally {
      setSubmitting(false);
    }
  }

  async function resendCode(event) {
    event.preventDefault();
    if (!email) {
      setMessage({ text: 'Email not found.' });
      return;
    }

    setMessage({ type: 'warning', text: 'Resending code...' });
    try {
      const formData = new FormData();
      formData.append('email', email);
      const response = await authFetch('/functions/resend_verification.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        authRequired: false,
      });
      const result = response ? await readJsonSafe(response) : null;

      if (!response || !response.ok || !result || !result.success) {
        setMessage({ text: (result && result.error) || 'Could not resend the code.' });
        if (result && result.redirect) {
          window.setTimeout(() => navigate(result.redirect, { replace: true }), 1200);
        }
        return;
      }

      setMessage({ type: 'success', text: result.message || 'A fresh code has been sent.' });
    } catch {
      setMessage({ text: 'Network error, please try again.' });
    }
  }

  return (
    <AuthShell
      title="Verify Email"
      subtitle="Complete your writer account"
      footer={(
        <>
          <a href="#resend" className="forgot-link" onClick={resendCode}>Resend code</a>
          <Link to="/register" className="forgot-link">Back to Register</Link>
        </>
      )}
    >
      <FormMessage message={message} />
      <form className="login-form" onSubmit={onSubmit}>
        <p className="verify-copy">
          A verification code was sent to your email. Enter it below to finish joining the map.
        </p>
        <div className="input-group">
          <input
            type="email"
            name="email"
            placeholder="Email address"
            required
            autoComplete="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
        </div>
        <div className="input-group">
          <input type="text" name="verification_code" placeholder="6-digit code" maxLength={6} pattern="\d{6}" required />
        </div>
        <button type="submit" className="menu-btn login-btn" disabled={submitting}>
          {submitting ? 'Verifying...' : 'Verify Email'}
        </button>
      </form>
    </AuthShell>
  );
}
