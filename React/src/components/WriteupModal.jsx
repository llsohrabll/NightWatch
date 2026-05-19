import { formatDate } from '../utils/date.js';

export default function WriteupModal({ writeup, onClose }) {
  if (!writeup) return null;

  return (
    <div className="writeup-modal show" aria-hidden="false" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose();
    }}>
      <div className="writeup-modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-writeup-title">
        <button className="modal-close" type="button" aria-label="Close writeup" onClick={onClose}>x</button>
        <p className="kicker">By {writeup.author?.username || 'Night Watch member'}</p>
        <h2 id="modal-writeup-title">{writeup.title || 'Untitled writeup'}</h2>
        <p className="modal-date">{formatDate(writeup.published_at_ms)}</p>
        <pre>{writeup.content_md || writeup.excerpt || ''}</pre>
      </div>
    </div>
  );
}
