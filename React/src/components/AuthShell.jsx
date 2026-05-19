import { Link } from 'react-router-dom';
import StarCanvas from './StarCanvas.jsx';
import useBodyClass from '../hooks/useBodyClass.js';

export default function AuthShell({ title, subtitle, children, footer }) {
  useBodyClass('auth-screen');

  return (
    <>
      <header className="page-header">
        <Link to="/" className="logo-link" aria-label="Night Watch home">
          <img src="/assets/nightwatch_symbol.png" alt="Night Watch" className="logo-icon" />
        </Link>
      </header>

      <StarCanvas id="bg-canvas" variant="public" />

      <div className="login-container">
        <div className="login-box">
          <h1 className="site-title glow-text">{title}</h1>
          <p className="site-subtitle">{subtitle}</p>
          {children}
          {footer ? <div className="login-footer">{footer}</div> : null}
        </div>
      </div>

      <div className="footer-signature">
        <span className="swords">*</span>
        <span className="glow-text">Night Watch Community</span>
        <span className="swords">*</span>
      </div>
    </>
  );
}
