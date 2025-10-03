import { getToken } from './auth.js'

let runtimeConfigPromise
async function getRuntimeConfig() {
  if (!runtimeConfigPromise) {
    runtimeConfigPromise = fetch('/gallerix.config.json')
      .then(r => r.ok ? r.json() : {})
      .catch(() => ({}))
  }
  return runtimeConfigPromise
}

async function resolveUrl(path) {
  const cfg = await getRuntimeConfig()
  const base = cfg.backendUrl || ''
  if (!base) return path
  return base.replace(/\/$/, '') + path
}

async function request(path, opts = {}) {
  const token = getToken()
  const url = await resolveUrl(path)
  const baseHeaders = opts.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }
  const res = await fetch(url, {
    ...opts,
    headers: {
      ...baseHeaders,
      ...(opts.headers || {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {})
    }
  })
  if (!res.ok) {
    let err
    try { err = await res.json() } catch { err = { error: res.statusText } }
    throw new Error(err.error || 'Request failed')
  }
  const text = await res.text()
  return text ? JSON.parse(text) : {}
}

export const api = {
  login: (username, password) => request('/api/login', { method: 'POST', body: JSON.stringify({ username, password }) }),
  me: () => request('/api/me'),
  listGalleries: () => request('/api/galleries'),
  listItems: (name) => request(`/api/galleries/${encodeURIComponent(name)}/items`),
  upload: (name, file) => {
    const fd = new FormData()
    fd.append('file', file)
    return request(`/api/galleries/${encodeURIComponent(name)}/upload`, { method: 'POST', body: fd })
  }
}
