import { getToken } from './auth.js'

async function request(path, opts = {}) {
  const token = getToken()
  const res = await fetch(path, {
    ...opts,
    headers: {
      'Content-Type': opts.body instanceof FormData ? undefined : 'application/json',
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
