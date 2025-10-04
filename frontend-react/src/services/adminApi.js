import { getToken } from './auth.js'

async function request(path, opts = {}) {
  const token = getToken()
  const res = await fetch(path, {
    ...opts,
    headers: {
      'Content-Type': 'application/json',
      ...(opts.headers || {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {})
    }
  })
  if (!res.ok) throw new Error((await res.json()).error || 'Request failed')
  return res.json()
}

export const adminApi = {
  listUsers: () => request('/api/admin/users'),
  upsertUser: (user) => request('/api/admin/users', { method: 'POST', body: JSON.stringify(user) }),
  deleteUser: (username) => request(`/api/admin/users/${encodeURIComponent(username)}`, { method: 'DELETE' }),

  getRoles: () => request('/api/admin/roles'),
  setRoles: (roles) => request('/api/admin/roles', { method: 'PUT', body: JSON.stringify(roles) }),

  listGalleries: () => request('/api/admin/galleries'),
  upsertGallery: (gallery) => request('/api/admin/galleries', { method: 'POST', body: JSON.stringify(gallery) }),
  deleteGallery: (name) => request(`/api/admin/galleries/${encodeURIComponent(name)}`, { method: 'DELETE' })
}
