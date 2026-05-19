import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AuthShell from '../components/AuthShell.jsx';
import FormMessage from '../components/FormMessage.jsx';
import { authFetch, getSession, readJsonSafe } from '../api/auth.js';

export default function ForgotPasswordPage() {
  const navigate = useNavigate();
  const [step, setStep] = useState(1);
  const [email, setEmail] = useState('');
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

  async function requestReset(event) {
    event.preventDefault();
    const nextEmail = event.currentTarget.email.value.trim();
    setEmail(nextEmail);
    setSubmitting(true);
    setMessage(null);

    try {
      const formData = new FormData();
      formData.append('email', nextEmail);
      const response = await authFetch('/functions/forget_password.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        authRequired: false,
      });
      const result = response ? await readJsonSafe(response) : null;

      if (!response || !response.ok || !result) {
        setMessage({ text: (result && result.error) || 'Request failed. Please try again.' });
        return;
      }

      if (result.success) {
        setMessage({ type: 'success', text: result.message || 'Code sent to your email.' });
        window.setTimeout(() => {
          setStep(2);
          setMessage(null);
        }, 1500);
      } else {
        setMessage({ text: result.error || 'Request failed.' });
      }
    } catch {
      setMessage({ text: 'Network error, please try again.' });
    } finally {
      setSubmitting(false);
    }
  }

  async function resetPassword(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const resetCode = form.reset_code.value.trim();
    const newPassword = form.new_password.value;
    const confirmPassword = form.confirm_password.value;
    setMessage(null);

    if (!/^\d{6}$/.test(resetCode)) {
      setMessage({ text: 'Reset code must be 6 digits.' });
      return;
    }
    if (newPassword !== confirmPassword) {
      setMessage({ text: 'Passwords do not match.' });
      return;
    }
    if (newPassword.length < 10 || !/[a-z]/.test(newPassword) || !/[A-Z]/.test(newPassword) || !/\d/.test(newPassword)) {
      setMessage({ text: 'Password must be at least 10 characters and include uppercase, lowercase, and a number.' });
      return;
    }

    setSubmitting(true);
    try {
      const formData = new FormData(form);
      formData.set('email', email);
      const response = await authFetch('/functions/reset_password.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        authRequired: false,
      });
      const result = response ? await readJsonSafe(response) : null;

      if (!response || !response.ok || !result) {
        setMessage({ text: (result && result.error) || 'Reset failed. Please try again.' });
        return;
      }

      if (result.success) {
        setMessage({ type: 'success', text: result.message || 'Password reset successful.' });
        window.setTimeout(() => navigate('/login', { replace: true }), 2000);
      } else {
        setMessage({ text: result.error || 'Reset failed.' });
      }
    } catch {
      setMessage({ text: 'Network error, please try again.' });
    } finally {
      setSubmitting(false);
    }
  }

  function backToStep1() {
    setStep(1);
    setMessage(null);
  }

  return (
    <AuthShell
      title="Recover Access"
      subtitle="Find your way back"
      footer={(
        <>
          <Link to="/login" className="forgot-link">Back to Login</Link>
          <Link to="/register" className="forgot-link">Do not have account? Register</Link>
        </>
      )}
    >
      <FormMessage message={message} />

      {step === 1 ? (
        <form className="login-form" onSubmit={requestReset}>
          <h3 className="step-heading">Step 1: Enter Email</h3>
          <div className="input-group">
            <input type="email" name="email" placeholder="Enter your email" required defaultValue={email} />
          </div>
          <button type="submit" className="menu-btn login-btn" disabled={submitting}>
            {submitting ? 'Sending...' : 'Send Reset Code'}
          </button>
        </form>
      ) : (
        <form className="login-form" onSubmit={resetPassword}>
          <h3 className="step-heading">Step 2: Reset Password</h3>
          <div className="input-group">
            <input type="text" name="email" placeholder="Email" value={email} readOnly />
          </div>
          <div className="input-group">
            <input type="text" name="reset_code" placeholder="6-digit code from email" maxLength={6} pattern="\d{6}" required />
          </div>
          <div className="input-group">
            <input type="password" name="new_password" placeholder="New password (min 10 chars, upper/lower/number)" required />
          </div>
          <div className="input-group">
            <input type="password" name="confirm_password" placeholder="Confirm password" required />
          </div>
          <button type="submit" className="menu-btn login-btn" disabled={submitting}>
            {submitting ? 'Resetting...' : 'Reset Password'}
          </button>
          <button type="button" className="menu-btn login-btn secondary-reset-btn" onClick={backToStep1}>
            Back to Step 1
          </button>
        </form>
      )}
    </AuthShell>
  );
}
