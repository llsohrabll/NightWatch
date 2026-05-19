import { Link } from 'react-router-dom';

export default function PublicHeader({ admin = false, onLogout }) {
  return (
    <header className={`site-header${admin ? ' admin-header' : ''}`}>
      <Link className="brand" to="/" aria-label="Night Watch home">
        <img src="/assets/nightwatch_symbol.png" alt="Night Watch" className="brand-logo" />
        <span>
          <strong>{admin ? 'Night Watch Admin' : 'Night Watch'}</strong>
          <small>{admin ? 'Moderate community writeups' : 'Login, register, and publish security writeups'}</small>
        </span>
      </Link>

      {admin ? (
        <nav className="site-nav" aria-label="Admin navigation">
          <Link to="/panel">Panel</Link>
          <Link to="/">Public site</Link>
          <button type="button" className="admin-nav-button" onClick={onLogout}>Log out</button>
        </nav>
      ) : (
        <>
          <nav className="site-nav" aria-label="Primary navigation">
            <a href="#portal">Portal</a>
            <a href="#writeups">Public writeups</a>
            <a href="#why">How it works</a>
          </nav>
          <div className="auth-links" aria-label="Authentication links">
            <Link className="login-link" to="/login">Login</Link>
            <Link className="register-link" to="/register">Register</Link>
          </div>
        </>
      )}
    </header>
  );
}
