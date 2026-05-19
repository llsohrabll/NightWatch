import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AuthShell from '../components/AuthShell.jsx';
import FormMessage from '../components/FormMessage.jsx';
import { authFetch, getSession, readJsonSafe } from '../api/auth.js';

export default function LoginPage() {
  const navigate = useNavigate();
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
    setSubmitting(true);
    setMessage(null);

    try {
      const formData = new FormData(event.currentTarget);
      const response = await authFetch('/functions/login.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        authRequired: false,
      });
      const result = response ? await readJsonSafe(response) : null;

      if (!response || !response.ok || !result) {
        setMessage({ text: (result && result.error) || 'Login failed. Please try again.' });
        if (result && result.requires_email_verification && result.email) {
          sessionStorage.setItem('registration_email', result.email);
          window.setTimeout(() => navigate(result.redirect || '/register/verify', { replace: true }), 900);
        }
        return;
      }

      if (result.success) {
        setMessage({ type: 'success', text: result.message || 'Login successful. Redirecting...' });
        window.setTimeout(() => navigate(result.redirect || '/panel', { replace: true }), 700);
      } else {
        setMessage({ text: result.error || 'Invalid username or password.' });
      }
    } catch {
      setMessage({ text: 'Network error, please try again.' });
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthShell
      title="Welcome Back"
      subtitle="Enter the observatory"
      footer={(
        <>
          <Link to="/forgetpassword" className="forgot-link">Forgot password?</Link>
          <Link to="/register" className="forgot-link">Register</Link>
        </>
      )}
    >
      <FormMessage message={message} />
      <form className="login-form" onSubmit={onSubmit}>
        <div className="input-group">
          <input type="text" name="username" placeholder="Username or email" required />
        </div>
        <div className="input-group">
          <input type="password" name="password" placeholder="Password" required />
        </div>
        <div className="checkbox-group">
          <label className="checkbox-label">
            <input type="checkbox" name="remember_me" />
            <span className="checkbox-text">Keep me signed in for one week</span>
          </label>
        </div>
        <button type="submit" className="menu-btn login-btn" disabled={submitting}>
          {submitting ? 'Authorising...' : 'Authorise'}
        </button>
      </form>
    </AuthShell>
  );
}
