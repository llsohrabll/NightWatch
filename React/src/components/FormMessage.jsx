export default function FormMessage({ message }) {
  const className = `form-message${message?.type ? ` ${message.type}` : ''}`;
  return <div className={className}>{message?.text || ''}</div>;
}
