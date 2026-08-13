const API_URL = (import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '')

let csrfToken = null

export const setCsrfToken = (token) => {
  csrfToken = token || null
}

export class ApiError extends Error {
  constructor(message, status, errors = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

export async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase()
  const headers = new Headers(options.headers || {})
  if (options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && csrfToken) {
    headers.set('X-CSRF-Token', csrfToken)
  }

  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    method,
    headers,
    credentials: 'include',
    body: options.body && !(options.body instanceof FormData)
      ? JSON.stringify(options.body)
      : options.body,
  })

  const payload = await response.json().catch(() => ({
    success: false,
    message: 'The server returned an unreadable response.',
  }))

  if (!response.ok || payload.success === false) {
    throw new ApiError(payload.message || 'Request failed.', response.status, payload.errors || null)
  }

  return payload.data ?? payload
}

export { API_URL }
