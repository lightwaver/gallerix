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

export async function resolveUrl(path) {
  const cfg = await getRuntimeConfig()
  const base = (cfg.backendUrl || '').replace(/\/$/, '')
  return base + path
}

async function request(path, opts = {}) {
  const token = getToken()
  const url = await resolveUrl(path)
  const isForm = opts.body instanceof FormData
  const headers = {
    ...(opts.headers || {}),
    ...(!isForm ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {})
  }
  const res = await fetch(url, { ...opts, headers })
  if (!res.ok) {
    let err
    try { err = await res.json() } catch { err = { error: res.statusText } }
    throw new Error(err.error || 'Request failed')
  }
  const text = await res.text()
  return text ? JSON.parse(text) : {}
}

export const api = {
  login: (username, password) =>
    request('/login', { method: 'POST', body: JSON.stringify({ username, password }) }),

  me: () => request('/me'),

  listGalleries: () => request('/galleries'),

  createGallery: (payload) =>
    request('/galleries', { method: 'POST', body: JSON.stringify(payload) }),

  listItems: (name) =>
    request(`/galleries/${encodeURIComponent(name)}/items`),

  // Upload with optional progress callback (onProgress receives percent 0-100)
  upload: (name, file, onProgress) => new Promise(async (resolve, reject) => {
    const token = getToken()
    const url = await resolveUrl(`/galleries/${encodeURIComponent(name)}/upload`)
    const fd = new FormData()
    fd.append('file', file)

    const xhr = new XMLHttpRequest()
    xhr.open('POST', url, true)
    if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`)

    if (xhr.upload && typeof onProgress === 'function') {
      xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
          // Pass both raw numbers and a percent
            onProgress({
              loaded: e.loaded,
              total: e.total,
              percent: Math.round(e.loaded / e.total * 100)
            })
        }
      }
    }

    xhr.onreadystatechange = () => {
      if (xhr.readyState === 4) {
        if (xhr.status >= 200 && xhr.status < 300) {
          try { resolve(xhr.responseText ? JSON.parse(xhr.responseText) : {}) } catch { resolve({}) }
        } else {
          try {
            const j = JSON.parse(xhr.responseText || '{}')
            reject(new Error(j.error || `Upload failed (${xhr.status})`))
          } catch {
            reject(new Error(`Upload failed (${xhr.status})`))
          }
        }
      }
    }

    xhr.onerror = () => reject(new Error('Network error during upload'))
    xhr.send(fd)
  })
}
