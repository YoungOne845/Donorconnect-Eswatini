export default function FormMessage({ type = 'error', message, errors }) {
  if (!message && !errors) return null
  return (
    <div className={`form-message ${type}`}>
      {message ? <strong>{message}</strong> : null}
      {errors ? (
        <ul>{Object.entries(errors).map(([field, text]) => <li key={field}>{text}</li>)}</ul>
      ) : null}
    </div>
  )
}
