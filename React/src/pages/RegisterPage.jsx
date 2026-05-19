import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AuthShell from '../components/AuthShell.jsx';
import FormMessage from '../components/FormMessage.jsx';
import { authFetch, getSession, readJsonSafe } from '../api/auth.js';

export default function RegisterPage() {
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
    const form = event.currentTarget;
    const email = form.email.value;
    const password = form.password.value;
    const confirmPassword = form.confirm_password.value;

    setMessage(null);
    if (password !== confirmPassword) {
      setMessage({ text: 'Passwords do not match.' });
      return;
    }

    setSubmitting(true);
    try {
      const formData = new FormData(form);
      formData.delete('confirm_password');
      const response = await authFetch('/functions/register.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        authRequired: false,
      });
      const result = response ? await readJsonSafe(response) : null;

      if (!response || !response.ok || !result) {
        setMessage({ text: (result && result.error) || 'Registration failed. Please try again.' });
        return;
      }

      if (result.success) {
        setMessage({ type: 'success', text: result.message || 'Registration successful. Redirecting...' });
        if (result.requires_email_verification) {
          sessionStorage.setItem('registration_email', email);
          if (result.user_id) sessionStorage.setItem('registration_user_id', result.user_id);
        }
        window.setTimeout(() => navigate(result.redirect || '/register/verify', { replace: true }), 1000);
      } else {
        setMessage({ text: result.error || 'Unknown error.' });
      }
    } catch {
      setMessage({ text: 'Network error, please try again.' });
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthShell
      title="Join the Map"
      subtitle="Create your writer account"
      footer={<Link to="/login" className="forgot-link">Already have an account?</Link>}
    >
      <FormMessage message={message} />
      <form className="login-form" onSubmit={onSubmit}>
        <div className="input-group">
          <input type="text" name="username" placeholder="Username" required />
        </div>
        <div className="input-group">
          <input type="email" name="email" placeholder="Email" required />
        </div>
        <div className="input-group">
          <input type="password" name="password" placeholder="Password" required />
        </div>
        <div className="input-group">
          <input type="password" name="confirm_password" placeholder="Retype password" required />
        </div>
        <button type="submit" className="menu-btn login-btn" disabled={submitting}>
          {submitting ? 'Registering...' : 'Register'}
        </button>
      </form>
    </AuthShell>
  );
}
